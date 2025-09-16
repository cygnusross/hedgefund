<?php

namespace App\Domain\FX;

use App\Models\Market;
use App\Services\IG\Client as IgClient;
use Illuminate\Contracts\Cache\Repository as CacheRepo;
use Illuminate\Support\Facades\Log as LogFacade;
use Psr\Log\LoggerInterface;

class SpreadEstimator
{
    /**
     * @param  callable|null  $marketFinder  function(string $pair): ?object Returns market-like object with 'epic' property
     */
    public function __construct(public IgClient $ig, public CacheRepo $cache, public ?LoggerInterface $logger = null, public $marketFinder = null) {}

    /**
     * @return float|null Spread in pips rounded to 1 decimal, or null on error/unavailable
     */
    public function estimatePipsForPair(string $pair, bool $bypassCache = false): ?float
    {
        // Normalize pair to match stored Market records
        $norm = strtoupper(str_replace('/', '-', $pair));

        // Try to find Market record by symbol or name. Allow an injected marketFinder for tests.
        if (is_callable($this->marketFinder)) {
            $market = call_user_func($this->marketFinder, $pair);
        } else {
            $market = Market::where('symbol', $norm)->orWhere('name', $pair)->first();
        }
        if (! $market || ! isset($market->epic) || empty($market->epic)) {
            $this->logWarning('no_epic_for_pair', ['pair' => $pair]);

            return null;
        }

        $epic = $market->epic;
        $cacheKey = "spreads:epic:{$epic}";

        // If caller requests to bypass cache, compute directly once
        if ($bypassCache) {
            $result = $this->computeSpreadForEpic($epic, $pair, $market);

            return is_array($result) ? ($result['spread'] ?? null) : null;
        }

        try {
            $result = $this->cache->remember($cacheKey, 30, function () use ($epic, $pair, $market) {
                return $this->computeSpreadForEpic($epic, $pair, $market);
            });

            return is_array($result) ? ($result['spread'] ?? null) : null;
        } catch (\Throwable $e) {
            $this->logWarning('cache_or_unexpected_error', ['pair' => $pair, 'epic' => $epic ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Return market status for a pair: 'OPEN'|'CLOSED' or null when unknown
     */
    public function getMarketStatusForPair(string $pair, bool $bypassCache = false): ?string
    {
        // Normalize pair
        $norm = strtoupper(str_replace('/', '-', $pair));

        if (is_callable($this->marketFinder)) {
            $market = call_user_func($this->marketFinder, $pair);
        } else {
            $market = Market::where('symbol', $norm)->orWhere('name', $pair)->first();
        }
        if (! $market || ! isset($market->epic) || empty($market->epic)) {
            return null;
        }

        $epic = $market->epic;
        $cacheKey = "spreads:epic:{$epic}";

        if ($bypassCache) {
            $res = $this->computeSpreadForEpic($epic, $pair, $market);

            return $res['status'] ?? null;
        }

        try {
            $res = $this->cache->remember($cacheKey, 30, function () use ($epic, $pair, $market) {
                return $this->computeSpreadForEpic($epic, $pair, $market);
            });

            return is_array($res) ? ($res['status'] ?? null) : null;
        } catch (\Throwable $e) {
            $this->logWarning('cache_or_unexpected_error', ['pair' => $pair, 'epic' => $epic ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Returns an array: ['spread' => float|null, 'status' => 'OPEN'|'CLOSED'|null]
     */
    protected function computeSpreadForEpic(string $epic, string $pair, object $market): array
    {
        // Note: do not require an API key here — test code may inject a mocked IG client which
        // does not rely on config('services.ig'). Proceed to call the injected client and handle
        // any errors via the surrounding try/catch.

        // Call IG markets endpoint
        try {
            $resp = $this->ig->get('/markets/'.urlencode($epic));
        } catch (\Throwable $e) {
            $this->logWarning('http_error', ['pair' => $pair, 'epic' => $epic, 'error' => $e->getMessage()]);

            return ['spread' => null, 'status' => null];
        }

        $body = $resp['body'] ?? null;
        if (! is_array($body)) {
            $this->logWarning('invalid_body', ['pair' => $pair, 'epic' => $epic]);

            return ['spread' => null, 'status' => null];
        }

        // Validate market status if present
        // Determine market status from snapshot or top-level keys
        $snapshot = $body['snapshot'] ?? null;
        $marketStatusRaw = null;
        if (is_array($snapshot) && isset($snapshot['marketStatus'])) {
            $marketStatusRaw = $snapshot['marketStatus'];
        } elseif (isset($body['marketStatus'])) {
            $marketStatusRaw = $body['marketStatus'];
        } elseif (isset($body['tradingStatus'])) {
            $marketStatusRaw = $body['tradingStatus'];
        } elseif (isset($body['marketState'])) {
            $marketStatusRaw = $body['marketState'];
        }

        $statusNormalized = null;
        if ($marketStatusRaw !== null) {
            $ms = strtoupper((string) $marketStatusRaw);
            if (str_contains($ms, 'CLOSED') || str_contains($ms, 'CLOSE')) {
                $statusNormalized = 'CLOSED';
            } elseif ($ms === 'TRADEABLE' || str_contains($ms, 'OPEN')) {
                $statusNormalized = 'OPEN';
            } else {
                $statusNormalized = $ms;
            }
        }

        // If marketStatus is provided and it's not 'OPEN', consider the market non-tradeable
        // and do not compute the spread. This aligns with expected behavior in tests.
        if ($statusNormalized !== null && $statusNormalized !== 'OPEN') {
            $this->logWarning('market_not_tradeable', ['pair' => $pair, 'epic' => $epic, 'status' => $statusNormalized]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        // Look for snapshot.bid and snapshot.offer
        if (! is_array($snapshot) || ! isset($snapshot['bid']) || ! isset($snapshot['offer'])) {
            $this->logWarning('missing_snapshot_bid_offer', ['pair' => $pair, 'epic' => $epic]);

            // Return status even when bid/offer missing; spread is null
            return ['spread' => null, 'status' => $statusNormalized];
        }

        $bid = (float) $snapshot['bid'];
        $offer = (float) $snapshot['offer'];

        if (! is_finite($bid) || ! is_finite($offer) || $offer <= $bid) {
            $this->logWarning('invalid_bid_offer', ['pair' => $pair, 'epic' => $epic, 'bid' => $bid, 'offer' => $offer]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        $pipSize = PipMath::pipSize($pair);
        if (! is_finite($pipSize) || $pipSize <= 0) {
            $this->logWarning('invalid_pip_size', ['pair' => $pair, 'epic' => $epic, 'pip_size' => $pipSize]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        // Handle IG Mini contracts that use scaled pricing
        $rawSpread = $offer - $bid;
        $actualSpread = $rawSpread;

        // Detect Mini contracts and Spread Betting contracts that use scaled pricing
        $instrumentName = $body['instrument']['name'] ?? '';
        $isScaledContract = str_contains($epic, 'MINI') ||
            str_contains(strtolower($instrumentName), 'mini') ||
            str_starts_with($epic, 'CS.D.'); // Spread betting contracts use raw format

        if ($isScaledContract) {
            // Mini contracts use POINTS-based pricing that needs scaling
            $scalingFactor = $this->detectMiniContractScalingFactor($pair, $bid);
            if ($scalingFactor > 1) {
                $actualSpread = $rawSpread / $scalingFactor;
                $this->logInfo('mini_contract_scaling_applied', [
                    'pair' => $pair,
                    'epic' => $epic,
                    'raw_spread' => $rawSpread,
                    'scaling_factor' => $scalingFactor,
                    'actual_spread' => $actualSpread,
                ]);
            }
        }

        $spread = $actualSpread / $pipSize;
        if (! is_finite($spread) || $spread <= 0) {
            $this->logWarning('non_positive_spread', ['pair' => $pair, 'epic' => $epic, 'spread' => $spread]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        // Round to 1 decimal as requested
        return ['spread' => round($spread, 1), 'status' => $statusNormalized];
    }

    protected function logWarning(string $reason, array $context = []): void
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->warning($reason, $context);

            return;
        }

        // Fallback to facade when a PSR logger wasn't injected
        LogFacade::warning($reason, $context);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info($message, $context);

            return;
        }

        // Fallback to facade when a PSR logger wasn't injected
        LogFacade::info($message, $context);
    }

    /**
     * Detect scaling factor for Mini contracts by comparing IG prices with expected FX ranges.
     * Mini contracts use POINTS-based pricing that differs from standard FX pricing.
     */
    protected function detectMiniContractScalingFactor(string $pair, float $igPrice): float
    {
        // Define expected price ranges for major FX pairs
        $expectedRanges = [
            'EUR/USD' => [1.0, 1.3],
            'EUR-USD' => [1.0, 1.3],
            'GBP/USD' => [1.1, 1.4],
            'GBP-USD' => [1.1, 1.4],
            'USD/JPY' => [100, 160],
            'USD-JPY' => [100, 160],
            'AUD/USD' => [0.6, 0.8],
            'AUD-USD' => [0.6, 0.8],
            'EUR/GBP' => [0.8, 0.95],
            'EUR-GBP' => [0.8, 0.95],
        ];

        $normalizedPair = strtoupper(str_replace(['/', '-'], ['/', '/'], $pair));
        $altPair = str_replace('/', '-', $normalizedPair);

        $expectedRange = $expectedRanges[$normalizedPair] ?? $expectedRanges[$altPair] ?? null;

        if (! $expectedRange || $igPrice <= 0) {
            return 1.0; // No scaling if we can't determine expected range
        }

        [$minExpected, $maxExpected] = $expectedRange;
        $midExpected = ($minExpected + $maxExpected) / 2;

        // If IG price is significantly outside expected range, calculate scaling factor
        if ($igPrice > $maxExpected * 10) {
            $scalingFactor = $igPrice / $midExpected;

            // Round to reasonable precision and ensure it's sensible
            return round($scalingFactor, 0);
        }

        return 1.0; // No scaling needed
    }
}
