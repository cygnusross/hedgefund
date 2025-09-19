<?php

namespace App\Application\Candles;

use App\Domain\Market\Bar;
use App\Infrastructure\Prices\CandleCacheContract;
use App\Services\Prices\PriceProvider;
use Carbon\Carbon;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class IncrementalCandleUpdater implements CandleUpdaterContract
{
    public function __construct(public PriceProvider $provider, public CandleCacheContract $cache) {}

    /**
     * Sync candles using an incremental tail fetch and merge with cache.
     *
     * @return array<Bar> oldest->newest
     */
    public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array
    {
        $lockKey = sprintf('candles:sync:%s:%s', strtoupper(str_replace(['/', '\\', ' '], '', $symbol)), $interval);

        // Default TTL from config
        $ttl = (int) config('pricing.cache_ttl', 3600);
        $lockTtl = (int) config('pricing.candle_sync_lock_seconds', 60);
        $lockWait = (int) config('pricing.candle_sync_lock_wait_seconds', 10);

        // Acquire a lock that lives long enough for a full sync to complete
        return Cache::lock($lockKey, max(5, $lockTtl))->block(max(1, $lockWait), function () use ($symbol, $interval, $bootstrapLimit, $tailFetchLimit, $ttl) {
            $cached = $this->cache->get($symbol, $interval) ?? [];

            // If no cache, bootstrap fully
            if (empty($cached)) {
                $raw = $this->provider->getCandles($symbol, ['interval' => $interval, 'outputsize' => $bootstrapLimit]);
                $bars = $this->normalizeCandles($raw);

                // Dual-write: cache for performance + database for backtesting
                $this->cache->put($symbol, $interval, $bars, $ttl);
                $this->persistToDatabase($symbol, $interval, $bars);

                return $bars;
            }

            // Ensure cached is sorted oldest->newest
            usort($cached, function (Bar $a, Bar $b) {
                return $a->ts <=> $b->ts;
            });

            $rawFresh = $this->provider->getCandles($symbol, ['interval' => $interval, 'outputsize' => $tailFetchLimit]);
            $fresh = $this->normalizeCandles($rawFresh);

            if (empty($fresh)) {
                // Nothing new — keep cached as-is
                return $cached;
            }

            // Don't truncate cache - let deduplication handle overlaps
            // $cutTs = $fresh[0]->ts ?? null;
            // The deduplication logic below will handle any overlapping timestamps

            // Ensure the freshest provider data wins if timestamps overlap
            $merged = array_merge($fresh, $cached);

            // Deduplicate by timestamp; optionally normalize to interval boundary
            $seen = [];
            $deduped = [];
            $normalize = (bool) config('pricing.normalize_timestamps', false);
            $intervalMinutes = 5;
            if (str_contains($interval, '30')) {
                $intervalMinutes = 30;
            } elseif (preg_match('/(\d+)min/', $interval, $m)) {
                $intervalMinutes = (int) $m[1];
            }

            foreach ($merged as $b) {
                $keyTs = $b->ts;
                if ($normalize) {
                    // floor to nearest interval multiple
                    $minute = (int) $keyTs->format('i');
                    $floor = $minute - ($minute % $intervalMinutes);
                    $keyTs = new \DateTimeImmutable($keyTs->format('Y-m-d').' '.$keyTs->format('H').':'.sprintf('%02d', $floor).':00', new \DateTimeZone('UTC'));
                }
                $k = $keyTs->format('c');
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $deduped[] = $b;
            }

            usort($deduped, function (Bar $a, Bar $b) {
                return $a->ts <=> $b->ts;
            });

            // Find truly new bars by comparing against what was cached
            $cachedTimestamps = [];
            foreach ($cached as $bar) {
                $cachedTimestamps[$bar->ts->format('c')] = true;
            }

            $newBars = [];
            foreach ($deduped as $bar) {
                if (! isset($cachedTimestamps[$bar->ts->format('c')])) {
                    $newBars[] = $bar;
                }
            }

            Log::info("IncrementalCandleUpdater new bars for $symbol $interval", [
                'new_bars_count' => count($newBars),
                'new_bars_times' => array_map(function ($bar) {
                    return $bar->ts->format('Y-m-d H:i:s');
                }, array_slice($newBars, -5)),
            ]);

            // Dual-write: cache for performance + database for backtesting
            $this->cache->put($symbol, $interval, $deduped, $ttl);
            $this->persistToDatabase($symbol, $interval, $newBars); // Only persist truly new bars

            return $deduped;
        });
    }

    /**
     * Normalize provider output into array<Bar> (oldest->newest).
     * Accepts either array<Bar> (already normalized) or legacy provider
     * shapes like ['prices' => [ ['time'=>..., 'open'=>...], ... ]] or a
     * plain array of associative rows.
     *
     * @return array<Bar>
     */
    private function normalizeCandles(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        // If provider already returned array of Bar objects
        $all = [];
        if (is_array($raw) && ! empty($raw)) {
            // Common provider wrapper: ['prices' => [...]]
            if (isset($raw['prices']) && is_array($raw['prices'])) {
                $rows = $raw['prices'];
            } elseif (array_values($raw) === $raw && is_array($raw[0] ?? null) && isset($raw[0]['time']) && isset($raw[0]['open'])) {
                // plain numeric array of associative rows
                $rows = $raw;
            } else {
                // Could already be an array of Bar objects
                $allObjects = true;
                foreach ($raw as $item) {
                    if (! $item instanceof Bar) {
                        $allObjects = false;
                        break;
                    }
                }
                if ($allObjects) {
                    $all = $raw;
                    // ensure oldest->newest
                    usort($all, function (Bar $a, Bar $b) {
                        return $a->ts <=> $b->ts;
                    });

                    return $all;
                }

                // Fallback: try to find 'prices' key or assume it's rows
                $rows = $raw['prices'] ?? $raw;
            }

            $bars = [];
            foreach ($rows as $row) {
                if ($row instanceof Bar) {
                    $bars[] = $row;

                    continue;
                }

                if (! is_array($row)) {
                    continue;
                }

                // Accept 'time' or 'datetime' keys
                $time = $row['time'] ?? $row['datetime'] ?? null;
                if (empty($time)) {
                    continue;
                }

                try {
                    $ts = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
                } catch (\Throwable $e) {
                    continue;
                }

                $open = isset($row['open']) ? (float) $row['open'] : null;
                $high = isset($row['high']) ? (float) $row['high'] : null;
                $low = isset($row['low']) ? (float) $row['low'] : null;
                $close = isset($row['close']) ? (float) $row['close'] : null;
                $volume = isset($row['volume']) ? (float) $row['volume'] : null;

                if ($open === null || $high === null || $low === null || $close === null) {
                    continue;
                }

                $bars[] = new Bar($ts, $open, $high, $low, $close, $volume);
            }

            usort($bars, function (Bar $a, Bar $b) {
                return $a->ts <=> $b->ts;
            });

            return $bars;
        }

        return [];
    }

    /**
     * Persist bars to database for backtesting, using logic from CandlesSeedHistory.
     * Only stores bars within London market hours (07:00-22:00 UTC).
     *
     * @param  array<Bar>  $bars
     */
    private function persistToDatabase(string $symbol, string $interval, array $bars): void
    {
        if (empty($bars)) {
            return;
        }

        $now = now();
        $candleData = [];

        foreach ($bars as $bar) {
            $timestamp = Carbon::parse($bar->ts);
            $hour = $timestamp->hour;

            // Only include candles within London market hours (07:00-22:00 UTC)
            // This matches the logic from CandlesSeedHistory
            if ($hour < 7 || $hour > 22) {
                continue;
            }

            $candleData[] = [
                'pair' => $symbol,
                'interval' => $interval,
                'timestamp' => $bar->ts,
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk upsert with duplicate handling - adapted for PostgreSQL
        if (! empty($candleData)) {
            try {
                // Use insertOrIgnore followed by updates for better PostgreSQL compatibility
                $inserted = DB::table('candles')->insertOrIgnore($candleData);

                // If some records weren't inserted (duplicates), update them
                if ($inserted < count($candleData)) {
                    foreach ($candleData as $data) {
                        DB::table('candles')
                            ->where('pair', $data['pair'])
                            ->where('interval', $data['interval'])
                            ->where('timestamp', $data['timestamp'])
                            ->update([
                                'open' => $data['open'],
                                'high' => $data['high'],
                                'low' => $data['low'],
                                'close' => $data['close'],
                                'volume' => $data['volume'],
                                'updated_at' => $data['updated_at'],
                            ]);
                    }
                }
            } catch (\Throwable $e) {
                // Log but don't fail - database persistence is secondary to cache
                Log::warning('Failed to persist candles to database', [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'count' => count($candleData),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
