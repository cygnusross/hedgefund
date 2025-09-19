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
            // Attempt to resolve epic from stored Market metadata first (preferred)
            $epic = $this->resolveEpic($symbol);

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

            // Sort oldest to newest (IG typically returns newest first)
            usort($bars, fn (Bar $a, Bar $b) => $a->ts <=> $b->ts);

            Log::info('IG price data retrieved successfully', [
                'epic' => $epic,
                'resolution' => $resolution->value,
                'num_points' => $numPoints,
                'bars_count' => count($bars),
                'allowance_remaining' => $historicalPricesResponse->allowance->remainingAllowance,
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
    protected function resolveEpic(string $symbol): string
    {
        $normalized = strtoupper(str_replace(' ', '', $symbol));
        $market = Market::where('symbol', $symbol)->orWhere('symbol', $normalized)->orWhere('name', $symbol)->first();
        if ($market && $market->epic) {
            return $market->epic;
        }

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
