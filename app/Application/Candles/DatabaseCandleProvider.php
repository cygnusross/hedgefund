<?php

namespace App\Application\Candles;

use App\Models\Candle;

final class DatabaseCandleProvider implements CandleUpdaterContract
{
    public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array
    {
        $candidates = $this->candidateSymbols($symbol);

        $limit = max($bootstrapLimit, $overlapBars + $tailFetchLimit);

        $candles = Candle::query()
            ->whereIn('pair', $candidates)
            ->where('interval', $interval)
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->sortBy('timestamp')
            ->values();

        if ($candles->isEmpty()) {
            return [];
        }

        return Candle::toBars($candles);
    }

    private function candidateSymbols(string $symbol): array
    {
        $normalized = strtoupper(trim($symbol));
        $compact = str_replace(['/', ' '], '', $normalized);
        $withSlash = strlen($compact) === 6 ? substr($compact, 0, 3).'/'.substr($compact, 3) : $normalized;
        $withDash = str_replace('/', '-', $withSlash);

        return array_unique([
            $symbol,
            $normalized,
            $withSlash,
            $withDash,
            $compact,
        ]);
    }
}
