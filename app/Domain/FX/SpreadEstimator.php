<?php

namespace App\Domain\FX;

use App\Domain\FX\Contracts\SpreadEstimatorContract;
use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;
use App\Models\Market;
use App\Services\IG\Client as IgClient;
use Illuminate\Contracts\Cache\Repository as CacheRepo;
use Illuminate\Support\Facades\Log as LogFacade;
use Psr\Log\LoggerInterface;

class SpreadEstimator implements SpreadEstimatorContract
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

        $rawBid = (float) $snapshot['bid'];
        $rawOffer = (float) $snapshot['offer'];

        if (! is_finite($rawBid) || ! is_finite($rawOffer) || $rawOffer <= $rawBid) {
            $this->logWarning('invalid_bid_offer', ['pair' => $pair, 'epic' => $epic, 'bid' => $rawBid, 'offer' => $rawOffer]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        $rawBidDecimal = Decimal::of($rawBid);
        $rawOfferDecimal = Decimal::of($rawOffer);
        $zeroDecimal = Decimal::of(0);
        $oneDecimal = Decimal::of(1);

        $priceScale = $this->resolvePriceScale($market, $pair, $body, $rawBid);
        $priceScaleDecimal = Decimal::of($priceScale);

        if ($priceScaleDecimal->isGreaterThan($oneDecimal)) {
            $bidDecimal = $rawBidDecimal->dividedBy($priceScaleDecimal, 12, RoundingMode::HALF_UP);
            $offerDecimal = $rawOfferDecimal->dividedBy($priceScaleDecimal, 12, RoundingMode::HALF_UP);
        } else {
            $bidDecimal = $rawBidDecimal;
            $offerDecimal = $rawOfferDecimal;
        }

        $pipSize = PipMath::pipSize($pair);
        $pipSizeDecimal = Decimal::of($pipSize);
        if ($pipSizeDecimal->isLessThan($zeroDecimal) || $pipSizeDecimal->isZero()) {
            $this->logWarning('invalid_pip_size', ['pair' => $pair, 'epic' => $epic, 'pip_size' => $pipSize]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        $rawSpreadDecimal = $rawOfferDecimal->minus($rawBidDecimal);
        $actualSpreadDecimal = $offerDecimal->minus($bidDecimal);
        $rawSpread = Decimal::toFloat($rawSpreadDecimal, 6);
        $actualSpread = Decimal::toFloat($actualSpreadDecimal, 6);

        if ($priceScale <= 1.0) {
            // Fallback: attempt automatic detection when we didn't have a predefined scale
            $instrumentName = $body['instrument']['name'] ?? '';
            $isScaledContract = str_contains($epic, 'MINI') ||
                str_contains(strtolower($instrumentName), 'mini') ||
                str_starts_with($epic, 'CS.D.');

            if ($isScaledContract) {
                $detectedScale = $this->detectMiniContractScalingFactor($pair, $rawBid);
                if ($detectedScale > 1.0) {
                    $priceScale = $detectedScale;
                    $priceScaleDecimal = Decimal::of($priceScale);
                    $bidDecimal = $rawBidDecimal->dividedBy($priceScaleDecimal, 12, RoundingMode::HALF_UP);
                    $offerDecimal = $rawOfferDecimal->dividedBy($priceScaleDecimal, 12, RoundingMode::HALF_UP);
                    $actualSpreadDecimal = $offerDecimal->minus($bidDecimal);
                    $actualSpread = Decimal::toFloat($actualSpreadDecimal, 6);
                    $this->logInfo('mini_contract_scaling_applied', [
                        'pair' => $pair,
                        'epic' => $epic,
                        'raw_spread' => $rawSpread,
                        'scaling_factor' => $detectedScale,
                        'actual_spread' => $actualSpread,
                    ]);
                }
            }
        }

        if ($actualSpreadDecimal->isLessThan($zeroDecimal) || $actualSpreadDecimal->isZero()) {
            $this->logWarning('non_positive_spread', [
                'pair' => $pair,
                'epic' => $epic,
                'raw_spread' => $rawSpread,
                'actual_spread' => $actualSpread,
                'price_scale' => $priceScale,
            ]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        $spreadDecimal = $actualSpreadDecimal->dividedBy($pipSizeDecimal, 12, RoundingMode::HALF_UP);
        $spread = Decimal::toFloat($spreadDecimal, 6);

        if ($spreadDecimal->isLessThan($zeroDecimal) || $spreadDecimal->isZero()) {
            $this->logWarning('non_positive_spread', ['pair' => $pair, 'epic' => $epic, 'spread' => $spread]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        $spreadLimitDecimal = Decimal::of(1000);
        if ($spreadDecimal->isGreaterThan($spreadLimitDecimal)) {
            $this->logWarning('spread_out_of_range', ['pair' => $pair, 'epic' => $epic, 'spread' => $spread, 'raw_spread' => $actualSpread]);

            return ['spread' => null, 'status' => $statusNormalized];
        }

        // Round to 1 decimal as requested
        $spreadRoundedDecimal = $spreadDecimal->toScale(1, RoundingMode::HALF_UP);

        return ['spread' => Decimal::toFloat($spreadRoundedDecimal, 1), 'status' => $statusNormalized];
    }

    protected function resolvePriceScale(object $market, string $pair, array $body, float $rawBid): float
    {
        $scaleFromMarket = null;
        if (isset($market->price_scale) && is_numeric($market->price_scale)) {
            $candidate = (float) $market->price_scale;
            if ($candidate > 1.0) {
                return $candidate;
            }
        }

        $snapshot = $body['snapshot'] ?? [];
        $snapshotScale = $snapshot['scalingFactor'] ?? null;
        if (is_numeric($snapshotScale) && (float) $snapshotScale > 1.0) {
            return (float) $snapshotScale;
        }

        $detected = $this->detectMiniContractScalingFactor($pair, $rawBid);

        return $detected > 1.0 ? $detected : 1.0;
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
            'NZD/USD' => [0.55, 0.75],
            'NZD-USD' => [0.55, 0.75],
        ];

        $normalizedPair = strtoupper(str_replace(['/', '-'], ['/', '/'], $pair));
        if (! str_contains($normalizedPair, '/') && strlen($normalizedPair) >= 6) {
            $normalizedPair = substr($normalizedPair, 0, 3) . '/' . substr($normalizedPair, 3, 3);
        }
        $altPair = str_replace('/', '-', $normalizedPair);

        $expectedRange = $expectedRanges[$normalizedPair] ?? $expectedRanges[$altPair] ?? null;

        if (! $expectedRange || $igPrice <= 0) {
            return 1.0; // No scaling if we can't determine expected range
        }

        [$minExpected, $maxExpected] = $expectedRange;
        $midExpected = ($minExpected + $maxExpected) / 2;

        // If IG price is significantly outside expected range, calculate scaling factor
        if ($igPrice > $maxExpected * 10) {
            $scalingDecimal = Decimal::of($igPrice)
                ->dividedBy(Decimal::of($midExpected), 12, RoundingMode::HALF_UP)
                ->toScale(0, RoundingMode::HALF_UP);

            return Decimal::toFloat($scalingDecimal, 0);
        }

        return 1.0; // No scaling needed
    }
}
