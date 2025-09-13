<?php

namespace App\Services\Prices;

interface PriceProvider
{
    /**
     * Return normalized candles for symbol.
     *
     * @return array<\App\Domain\Market\Bar> Oldest -> newest
     */
    public function getCandles(string $symbol, array $params = []): array;
}
