<?php

namespace App\Infrastructure\Prices;

use App\Domain\Market\Bar;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

interface CandleCacheContract
{
    /** @return array<Bar>|null */
    public function get(string $symbol, string $interval): ?array;

    /** @param array<Bar> $bars */
    public function put(string $symbol, string $interval, array $bars, int $ttlSeconds = 0): void;

    public function tailTs(string $symbol, string $interval): ?\DateTimeImmutable;
}
