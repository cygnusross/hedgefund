<?php

namespace App\Services\Prices;

use App\Domain\Market\Bar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwelveDataProvider implements PriceProvider
{
    public function __construct(protected ?string $apiKey = null, protected ?string $baseUrl = null, protected int $timeout = 8)
    {
        $this->apiKey = $apiKey ?? config('pricing.twelvedata.api_key');
        $this->baseUrl = $baseUrl ?? config('pricing.twelvedata.base_url', 'https://api.twelvedata.com');
        $this->timeout = $timeout ?: (int) config('pricing.twelvedata.timeout', 8);
    }

    /**
     * Params accepted: interval (e.g., '5min'), outputsize (int), start_date, end_date, timezone
     *
     * @return array<App\Domain\Market\Bar> Oldest -> newest
     */
    public function getCandles(string $symbol, array $params = []): array
    {
        $interval = $params['interval'] ?? (config('pricing.twelvedata.interval_map.MINUTE_5') ?? '5min');
        $outputsize = $params['outputsize'] ?? 5000;

        $query = array_filter([
            'symbol' => $symbol,
            'interval' => $interval,
            'outputsize' => $outputsize,
            'format' => 'JSON',
            'apikey' => $this->apiKey,
            'start_date' => $params['start_date'] ?? null,
            'end_date' => $params['end_date'] ?? null,
            'timezone' => $params['timezone'] ?? 'UTC',
        ]);

        $resp = Http::baseUrl($this->baseUrl)
            ->timeout(config('pricing.twelvedata.timeout', 8))
            ->retry(2, 200)
            ->get('/time_series', $query);

        $status = $resp->status();
        $body = $resp->body();

        if ($status < 200 || $status >= 300) {
            Log::warning('TwelveData HTTP error', [
                'symbol' => $symbol,
                'params' => $params,
                'status' => $status,
                'response_body' => $body,
                'query' => $query,
            ]);
            throw new \RuntimeException("TwelveData HTTP {$status}: {$body}");
        }

        $data = $resp->json();

        $values = $data['values'] ?? null;
        if (! is_array($values) || empty($values)) {
            Log::warning('TwelveData empty/missing values', [
                'symbol' => $symbol,
                'params' => $params,
                'status' => $status,
                'response_data' => $data,
                'query' => $query,
            ]);
            throw new \RuntimeException('TwelveData: missing/empty values');
        }

        $bars = [];
        foreach ($values as $row) {
            if (empty($row['datetime']) || ! isset($row['open'], $row['high'], $row['low'], $row['close'])) {
                // skip incomplete rows
                continue;
            }

            try {
                $ts = new \DateTimeImmutable($row['datetime'], new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                continue; // skip unparsable datetime
            }

            $bars[] = new Bar(
                $ts,
                (float) $row['open'],
                (float) $row['high'],
                (float) $row['low'],
                (float) $row['close'],
                isset($row['volume']) ? (float) $row['volume'] : null,
            );
        }

        // Ensure oldest -> newest ordering by timestamp
        usort($bars, function (Bar $a, Bar $b) {
            return $a->ts <=> $b->ts;
        });

        // Return array<Bar> oldest->newest
        return $bars;
    }
}
