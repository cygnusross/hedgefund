<?php

namespace App\Services\MarketStatus;

use App\Domain\Market\Contracts\MarketStatusProvider;

final class NullMarketStatusProvider implements MarketStatusProvider
{
    public function fetch(string $pair, \DateTimeImmutable $nowUtc, array $opts = []): array
    {
        return [
            'status' => 'UNKNOWN',
            'quote_age_sec' => null,
            'market_id' => null,
        ];
    }
}
