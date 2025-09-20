<?php

namespace App\Services\Prices;

use App\Domain\Market\Bar;
use App\Models\Market;
use App\Services\IG\DTO\HistoricalPricesResponse;
use App\Services\IG\Endpoints\HistoricalPricesEndpoint;
use App\Services\IG\Enums\Resolution;
use Illuminate\Support\Facades\Log;

class IgPriceProvider implements PriceProvider
{
    public function __construct(
        protected HistoricalPricesEndpoint $endpoint
    ) {}

    /**
     * Return normalized candles for symbol.
     *
     * @return array<\App\Domain\Market\Bar> Oldest -> newest
     */
    public function getCandles(string $symbol, array $params = []): array
    {
        try {
            $marketModel = $this->findMarket($symbol);
            // Attempt to resolve epic from stored Market metadata first (preferred)
            $epic = $marketModel?->epic ?? $this->resolveEpicFromSymbol($symbol);

            // Get resolution from params or use default
            $interval = $params['interval'] ?? '5min';
            $resolution = Resolution::fromInterval($interval);

            // Get number of points from params or use default
            $numPoints = $params['outputsize'] ?? 100;

            // Call the IG API
            $response = $this->endpoint->get($epic, $resolution, $numPoints);

            // Parse response into DTO
            $historicalPricesResponse = HistoricalPricesResponse::fromArray($response);

            // Convert to Bar objects
            $bars = $historicalPricesResponse->toBars();
            $bars = $this->maybeNormalizeBars($symbol, $bars, $marketModel);

            // Sort oldest to newest (IG typically returns newest first)
            usort($bars, fn (Bar $a, Bar $b) => $a->ts <=> $b->ts);

            Log::info('IG price data retrieved successfully', [
                'epic' => $epic,
                'resolution' => $resolution->value,
                'num_points' => $numPoints,
                'bars_count' => count($bars),
                'allowance_remaining' => $historicalPricesResponse->allowance->remainingAllowance,
                'price_scale' => $marketModel?->price_scale,
            ]);

            return $bars;
        } catch (\Exception $e) {
            Log::error('IG price data retrieval failed', [
                'symbol' => $symbol,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to retrieve IG price data for {$symbol}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Convert symbol format to IG epic format
     *
     * Examples:
     * EUR/USD -> CS.D.EURUSD.MINI.IP
     * GBP/USD -> CS.D.GBPUSD.MINI.IP
     * USD/JPY -> CS.D.USDJPY.MINI.IP
     */
    protected function resolveEpicFromSymbol(string $symbol): string
    {
        // Handle forex pairs
        if (str_contains($symbol, '/')) {
            $pair = str_replace('/', '', $symbol);

            return "CS.D.{$pair}.TODAY.IP";
        }

        // If already in epic format, return as-is
        if (str_contains($symbol, '.')) {
            return $symbol;
        }

        // Default forex TODAY epic format
        return "CS.D.{$symbol}.TODAY.IP";
    }

    protected function findMarket(string $symbol): ?Market
    {
        $query = Market::query();
        $query->where('symbol', $symbol)
            ->orWhere('name', $symbol);

        $upper = strtoupper($symbol);
        $query->orWhere('symbol', $upper);

        $normalized = strtoupper(str_replace(' ', '', $symbol));
        if (strlen($normalized) >= 6 && ! str_contains($normalized, '/')) {
            $normalized = substr($normalized, 0, 3).'/'.substr($normalized, 3, 3);
        }

        $dashVariant = str_replace('/', '-', $normalized);

        $query->orWhere('symbol', $normalized)
            ->orWhere('symbol', $dashVariant);

        return $query->first();
    }

    /**
     * Normalize IG price data when raw values are expressed in points instead of decimals.
     *
     * @param  Bar[]  $bars
     * @return Bar[]
     */
    protected function maybeNormalizeBars(string $symbol, array $bars, ?Market $market): array
    {
        if (empty($bars)) {
            return $bars;
        }

        $priceScale = $market?->price_scale;
        if (! is_numeric($priceScale) || (float) $priceScale <= 1.0) {
            return $bars;
        }

        $maxMagnitude = 0.0;
        foreach ($bars as $bar) {
            $maxMagnitude = max(
                $maxMagnitude,
                abs($bar->open),
                abs($bar->high),
                abs($bar->low),
                abs($bar->close)
            );
        }

        // IG historical prices for FX mini contracts often arrive in raw points (>= 1000)
        if ($maxMagnitude < 1000) {
            return $bars;
        }

        $scale = (float) $priceScale;

        Log::info('ig_price_provider_scaling_applied', [
            'symbol' => $symbol,
            'price_scale' => $scale,
            'max_raw_price' => $maxMagnitude,
        ]);

        return array_map(function (Bar $bar) use ($scale) {
            return new Bar(
                $bar->ts,
                $bar->open / $scale,
                $bar->high / $scale,
                $bar->low / $scale,
                $bar->close / $scale,
                $bar->volume
            );
        }, $bars);
    }

    /**
     * Convert IG epic back to symbol format
     *
     * CS.D.EURUSD.MINI.IP -> EUR/USD
     */
    public function epicToSymbol(string $epic): string
    {
        // Extract pair from IG epic format
        $parts = explode('.', $epic);
        if (count($parts) >= 3 && $parts[0] === 'CS' && $parts[1] === 'D') {
            $pair = $parts[2];

            // Add slash for forex pairs (6 characters)
            if (strlen($pair) === 6) {
                return substr($pair, 0, 3).'/'.substr($pair, 3, 3);
            }
        }

        // Return as-is if not in expected format
        return $epic;
    }
}
