<?php

namespace App\Infrastructure\Prices;

use App\Domain\Market\Bar;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Support\Facades\Redis;

final class CandleCache
{
    public static function key(string $symbol, string $interval): string
    {
        $sym = strtoupper(str_replace(['/', '\\', ' '], '', $symbol));

        return "candles:{$sym}:{$interval}";
    }

    /**
     * Return array<Bar>|null
     */
    public static function get(string $symbol, string $interval): ?array
    {
        $key = self::key($symbol, $interval);
        $raw = Redis::get($key);
        if ($raw === null) {
            return null;
        }

        $obj = json_decode($raw, true);
        if (! is_array($obj)) {
            return null;
        }

        $barsArr = $obj['bars'] ?? $obj;
        if (! is_array($barsArr)) {
            return null;
        }

        $bars = [];
        foreach ($barsArr as $row) {
            if (! isset($row['ts'])) {
                continue;
            }

            try {
                $ts = new \DateTimeImmutable($row['ts']);
            } catch (\Throwable $e) {
                continue;
            }

            $bars[] = new Bar(
                $ts,
                (float) ($row['open'] ?? 0.0),
                (float) ($row['high'] ?? 0.0),
                (float) ($row['low'] ?? 0.0),
                (float) ($row['close'] ?? 0.0),
                isset($row['volume']) ? (float) $row['volume'] : null
            );
        }

        return $bars;
    }

    /**
     * Store bars (array<Bar>) oldest->newest
     */
    public static function put(string $symbol, string $interval, array $bars, int $ttlSeconds = 0): void
    {
        $key = self::key($symbol, $interval);

        $payloadBars = [];
        foreach ($bars as $bar) {
            /* @var Bar $bar */
            $payloadBars[] = [
                'ts' => $bar->ts->format(DATE_ATOM),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
            ];
        }

        $payload = json_encode(['v' => 1, 'bars' => $payloadBars]);

        if ($ttlSeconds > 0) {
            Redis::setex($key, $ttlSeconds, $payload);
        } else {
            Redis::set($key, $payload);
        }
    }

    public static function tailTs(string $symbol, string $interval): ?\DateTimeImmutable
    {
        $key = self::key($symbol, $interval);
        $raw = Redis::get($key);
        if ($raw === null) {
            return null;
        }

        $obj = json_decode($raw, true);
        $barsArr = $obj['bars'] ?? $obj;
        if (! is_array($barsArr) || count($barsArr) === 0) {
            return null;
        }

        $last = end($barsArr);
        if (! isset($last['ts'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable($last['ts']);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
