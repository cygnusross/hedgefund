<?php

use App\Application\News\NewsAggregator;
use App\Services\News\NewsProvider;

it('returns stats-only shape when provider supports fetchStat', function () {
    // Create a mock provider that has fetchStat
    $provider = new class implements NewsProvider
    {
        public function fetch(string $symbol, int $items = 10, int $page = 1, ?array $options = null): array
        {
            return [];
        }

        public function fetchStat(string $pair, string $date = 'today', bool $fresh = false): array
        {
            return ['pair' => $pair, 'date' => $date, 'pos' => 2, 'neg' => 1, 'neu' => 0, 'score' => 0.3];
        }
    };

    $agg = new NewsAggregator($provider);
    $summary = $agg->summary('EUR/USD', 'today', false);

    expect($summary)->toBeArray()
        ->toHaveKey('direction')
        ->toHaveKey('strength')
        ->toHaveKey('counts')
        ->not->toHaveKey('latest_at')
        ->not->toHaveKey('sources');

    // counts structure
    expect($summary['counts'])->toBeArray()
        ->toHaveKey('pos')
        ->toHaveKey('neg')
        ->toHaveKey('neu');
});
