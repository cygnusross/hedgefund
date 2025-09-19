<?php

namespace App\Domain\FX;

use App\Domain\FX\Contracts\SpreadEstimatorContract;
use App\Models\Spread;

class DatabaseSpreadEstimator implements SpreadEstimatorContract
{
    public function estimatePipsForPair(string $pair, bool $bypassCache = false): ?float
    {
        $spread = $this->latestSpread($pair);

        return $spread ? (float) $spread->spread_pips : null;
    }

    public function getMarketStatusForPair(string $pair, bool $bypassCache = false): ?string
    {
        return $this->latestSpread($pair) ? 'TRADEABLE' : null;
    }

    private function latestSpread(string $pair): ?Spread
    {
        $candidates = $this->candidateSymbols($pair);

        return Spread::query()
            ->whereIn('pair', $candidates)
            ->orderByDesc('recorded_at')
            ->first();
    }

    private function candidateSymbols(string $pair): array
    {
        $normalized = strtoupper(trim($pair));
        $compact = str_replace(['/', ' '], '', $normalized);
        $withSlash = strlen($compact) === 6 ? substr($compact, 0, 3).'/'.substr($compact, 3) : $normalized;
        $withDash = str_replace('/', '-', $withSlash);

        return array_unique([
            $pair,
            $normalized,
            $withSlash,
            $withDash,
            $compact,
        ]);
    }
}
