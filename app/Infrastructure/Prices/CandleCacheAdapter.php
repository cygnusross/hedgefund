<?php

namespace App\Infrastructure\Prices;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

final class CandleCacheAdapter implements CandleCacheContract
{
    public function get(string $symbol, string $interval): ?array
    {
        return CandleCache::get($symbol, $interval);
    }

    public function put(string $symbol, string $interval, array $bars, int $ttlSeconds = 0): void
    {
        CandleCache::put($symbol, $interval, $bars, $ttlSeconds);
    }

    public function tailTs(string $symbol, string $interval): ?\DateTimeImmutable
    {
        return CandleCache::tailTs($symbol, $interval);
    }
}
