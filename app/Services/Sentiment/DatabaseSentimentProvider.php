<?php

namespace App\Services\Sentiment;

use App\Domain\Sentiment\Contracts\SentimentProvider;
use App\Models\ClientSentiment;
use App\Models\Market;

final class DatabaseSentimentProvider implements SentimentProvider
{
    public function fetch(string $marketId, bool $force = false): ?array
    {
        $record = ClientSentiment::query()
            ->where('market_id', $marketId)
            ->orWhere('pair', $this->normalizePair($marketId))
            ->latest('recorded_at')
            ->first();

        if (! $record) {
            $market = Market::where('epic', $marketId)->first();
            if ($market) {
                $record = ClientSentiment::query()
                    ->where('pair', $market->symbol)
                    ->latest('recorded_at')
                    ->first();
            }
        }

        if (! $record) {
            return null;
        }

        return [
            'long_pct' => (float) $record->long_pct,
            'short_pct' => (float) $record->short_pct,
            'as_of' => $record->recorded_at?->toIso8601String() ?? now('UTC')->toIso8601String(),
        ];
    }

    private function normalizePair(string $marketId): string
    {
        $upper = strtoupper($marketId);

        if (str_contains($upper, '.')) {
            return $upper;
        }

        if (strlen($upper) === 6) {
            return substr($upper, 0, 3).'/'.substr($upper, 3);
        }

        return $upper;
    }
}
