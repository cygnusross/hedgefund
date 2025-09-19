<?php

namespace App\Domain\Market\Contracts;

interface MarketStatusProvider
{
    /**
     * @return array{status: ?string, quote_age_sec: ?int, market_id: ?string}
     */
    public function fetch(string $pair, \DateTimeImmutable $nowUtc, array $opts = []): array;
}
