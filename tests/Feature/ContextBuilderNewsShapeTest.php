<?php

use App\Application\Calendar\CalendarLookup;
use App\Application\Candles\CandleUpdaterContract;
use App\Application\ContextBuilder;
use App\Application\News\NewsAggregator;
use App\Domain\Market\Bar;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

it('ensures ContextBuilder exposes only stats fields in news', function () {
    // Fake CandleUpdater that returns enough bars for FeatureEngine
    $candleUpdater = new class implements CandleUpdaterContract
    {
        public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            $bars = [];
            $now = new \DateTimeImmutable('2025-09-12T12:00:00+00:00');
            if ($interval === '5min') {
                // create 30 bars, 5min apart
                for ($i = 0; $i < 30; $i++) {
                    $ts = $now->modify('-'.(5 * (29 - $i)).' minutes');
                    $open = 1.1000 + ($i * 0.0001);
                    $close = $open + 0.00005;
                    $bars[] = new Bar($ts, $open, $open + 0.0001, $open - 0.0001, $close, 1000);
                }
            } else {
                // 30m bars: create 30 bars, 30min apart
                for ($i = 0; $i < 30; $i++) {
                    $ts = $now->modify('-'.(30 * (29 - $i)).' minutes');
                    $open = 1.1000 + ($i * 0.0002);
                    $close = $open + 0.0001;
                    $bars[] = new Bar($ts, $open, $open + 0.0002, $open - 0.0002, $close, 1000);
                }
            }

            return $bars;
        }
    };

    // Fake NewsProvider that implements fetchStat (stats-only)
    $newsProvider = new class implements \App\Services\News\NewsProvider
    {
        public function fetchStat(string $pair, string $date = 'today', bool $fresh = false): array
        {
            // return aggregated stat counts for the requested date
            return ['pair' => str_replace('/', '-', strtoupper($pair)), 'date' => $date, 'pos' => 2, 'neg' => 1, 'neu' => 0, 'score' => 0.25];
        }
    };

    $newsAgg = new NewsAggregator($newsProvider);

    // Fake EconomicCalendarProviderContract for CalendarLookup
    $econProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            return [];
        }

        // ingest is optional for providers used only as a stub in tests,
        // but it must be implemented to satisfy the contract.
        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    $calendar = new \App\Application\Calendar\CalendarLookup($econProvider);

    $builder = new ContextBuilder($candleUpdater, $newsAgg, $calendar);
    $ts = new \DateTimeImmutable('2025-09-12T12:00:00+00:00');

    $payload = $builder->build('EUR/USD', $ts);
    expect($payload)->toBeArray();
    expect($payload['news'])->toBeArray()
        ->toHaveKey('direction')
        ->toHaveKey('strength')
        ->toHaveKey('counts')
        ->not->toHaveKey('latest_at')
        ->not->toHaveKey('sources');
});
