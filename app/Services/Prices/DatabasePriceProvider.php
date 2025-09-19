<?php

namespace App\Services\Prices;

use App\Application\Candles\DatabaseCandleProvider;

class DatabasePriceProvider implements PriceProvider
{
    public function __construct(private DatabaseCandleProvider $provider)
    {
    }

    public function getCandles(string $symbol, array $params = []): array
    {
        $interval = $params['interval'] ?? '5min';
        $bootstrapLimit = $params['outputsize'] ?? 150;
        $overlap = $params['overlap'] ?? 0;
        $tail = $params['outputsize'] ?? 0;

        return $this->provider->sync($symbol, $interval, $bootstrapLimit, $overlap, $tail);
    }
}
