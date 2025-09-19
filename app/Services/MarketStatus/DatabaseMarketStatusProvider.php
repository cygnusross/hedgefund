<?php

namespace App\Services\MarketStatus;

use App\Domain\Market\Contracts\MarketStatusProvider;
use App\Models\Market;
use App\Models\Spread;

final class DatabaseMarketStatusProvider implements MarketStatusProvider
{
    public function fetch(string $pair, \DateTimeImmutable $nowUtc, array $opts = []): array
    {
        $market = Market::where('symbol', $pair)->first();
        $epic = $market && $market->epic ? $market->epic : strtoupper(str_replace('/', '-', $pair));

        $spread = Spread::query()
            ->whereIn('pair', $this->candidateSymbols($pair))
            ->latest('recorded_at')
            ->first();

        $status = $spread ? 'TRADEABLE' : 'UNKNOWN';

        return [
            'status' => $status,
            'quote_age_sec' => null,
            'market_id' => $epic,
        ];
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
