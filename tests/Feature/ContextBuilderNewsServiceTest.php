<?php

declare(strict_types=1);

use App\Application\Calendar\CalendarLookup;
use App\Application\Candles\CandleUpdaterContract;
use App\Application\ContextBuilder;
use App\Application\News\NewsCacheKeyStrategy;
use App\Application\News\NewsData;
use App\Application\News\NewsServiceInterface;
use App\Domain\Market\Bar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses NewsService when provided', function () {
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
                    $ts = $now->modify('-' . (5 * (29 - $i)) . ' minutes');
                    $open = 1.1000 + ($i * 0.0001);
                    $close = $open + 0.00005;
                    $bars[] = new Bar($ts, $open, $open + 0.0001, $open - 0.0001, $close, 1000);
                }
            } else {
                // 30m bars: create 30 bars, 30min apart
                for ($i = 0; $i < 30; $i++) {
                    $ts = $now->modify('-' . (30 * (29 - $i)) . ' minutes');
                    $open = 1.1000 + ($i * 0.0002);
                    $close = $open + 0.0001;
                    $bars[] = new Bar($ts, $open, $open + 0.0002, $open - 0.0002, $close, 1000);
                }
            }

            return $bars;
        }
    };

    // Fake calendar provider
    $calendarProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            return [];
        }

        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    // Mock NewsService
    $mockNewsService = $this->mock(NewsServiceInterface::class);
    $mockNewsService->shouldReceive('getNews')
        ->once()
        ->with('EUR/USD', '2025-09-12')
        ->andReturn(new NewsData(
            rawScore: 0.5,
            strength: 0.75,
            counts: ['pos' => 3, 'neg' => 1, 'neu' => 0],
            pair: 'EUR/USD',
            date: '2025-09-12'
        ));

    // Create ContextBuilder with NewsService
    $builder = new ContextBuilder(
        $candleUpdater,
        new CalendarLookup($calendarProvider),
        null, // spreadEstimator
        null, // sentimentProvider
        null, // markets
        null, // cacheRepo
        $mockNewsService
    );

    $now = new \DateTimeImmutable('2025-09-12T12:00:00+00:00');
    $result = $builder->build('EUR/USD', $now, '2025-09-12'); // Use explicit date instead of 'today'

    expect($result)->not->toBeNull();
    expect($result['news'])->toBe([
        'direction' => 'buy',
        'strength' => 0.75,
        'counts' => ['pos' => 3, 'neg' => 1, 'neu' => 0],
        'raw_score' => 0.5,
        'date' => '2025-09-12',
    ]);
});

it('falls back to old logic when NewsService not provided', function () {
    // This test verifies backward compatibility
    // We'll create a cache entry and make sure it still works without NewsService

    // Set up cache data
    $cacheKey = NewsCacheKeyStrategy::statKey('EUR/USD', '2025-09-12');
    \Illuminate\Support\Facades\Cache::put($cacheKey, [
        'raw_score' => 0.3,
        'strength' => 0.6,
        'counts' => ['pos' => 2, 'neg' => 1, 'neu' => 1],
    ], 600);

    // Fake CandleUpdater
    $candleUpdater = new class implements CandleUpdaterContract
    {
        public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            $bars = [];
            $now = new \DateTimeImmutable('2025-09-12T12:00:00+00:00');
            if ($interval === '5min') {
                for ($i = 0; $i < 30; $i++) {
                    $ts = $now->modify('-' . (5 * (29 - $i)) . ' minutes');
                    $open = 1.1000 + ($i * 0.0001);
                    $close = $open + 0.00005;
                    $bars[] = new Bar($ts, $open, $open + 0.0001, $open - 0.0001, $close, 1000);
                }
            } else {
                for ($i = 0; $i < 30; $i++) {
                    $ts = $now->modify('-' . (30 * (29 - $i)) . ' minutes');
                    $open = 1.1000 + ($i * 0.0002);
                    $close = $open + 0.0001;
                    $bars[] = new Bar($ts, $open, $open + 0.0002, $open - 0.0002, $close, 1000);
                }
            }
            return $bars;
        }
    };

    // Fake calendar provider
    $calendarProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            return [];
        }

        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    // Create ContextBuilder WITHOUT NewsService (backward compatibility)
    $builder = new ContextBuilder(
        $candleUpdater,
        new CalendarLookup($calendarProvider)
    );

    $now = new \DateTimeImmutable('2025-09-12T12:00:00+00:00');
    $result = $builder->build('EUR/USD', $now, '2025-09-12'); // Use explicit date instead of 'today'

    expect($result)->not->toBeNull();
    expect($result['news'])->toBe([
        'direction' => 'buy', // 0.3 > 0
        'strength' => 0.6,
        'counts' => ['pos' => 2, 'neg' => 1, 'neu' => 1],
        'raw_score' => 0.3,
        'date' => '2025-09-12',
    ]);
});
