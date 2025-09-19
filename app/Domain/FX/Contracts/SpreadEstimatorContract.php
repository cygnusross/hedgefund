<?php

namespace App\Domain\FX\Contracts;

interface SpreadEstimatorContract
{
    public function estimatePipsForPair(string $pair, bool $bypassCache = false): ?float;

    public function getMarketStatusForPair(string $pair, bool $bypassCache = false): ?string;
}
