<?php

namespace App\Application\Candles;

use App\Domain\Market\Bar;

interface CandleUpdaterContract
{
    /**
     * Sync and return candles for the given symbol/interval. Oldest->newest
     *
     * @return array<Bar>
     */
    public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array;
}
