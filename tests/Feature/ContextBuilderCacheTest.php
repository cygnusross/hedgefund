<?php

declare(strict_types=1);

use App\Application\Calendar\CalendarLookup;
use App\Application\ContextBuilder;
use App\Application\News\NewsCacheKeyStrategy;
use App\Domain\Market\Bar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('ContextBuilder uses cache-only news retrieval', function () {
    $now = new \DateTimeImmutable('2024-01-15 12:00:00', new \DateTimeZone('UTC'));

    // Build deterministic bars for 5m and 30m with enough history
    $bars5 = [];
    for ($i = 0; $i < 100; $i++) {
        $ts = $now->sub(new \DateInterval('PT'.(5 * (100 - $i)).'M'));
        $bars5[] = new Bar($ts, 1.0950, 1.0955, 1.0945, 1.0952, 100);
    }

    $bars30 = [];
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->sub(new \DateInterval('PT'.(30 * (50 - $i)).'M'));
        $bars30[] = new Bar($ts, 1.0950, 1.0955, 1.0945, 1.0952, 100);
    }

    $updater = new class($bars5, $bars30) implements \App\Application\Candles\CandleUpdaterContract
    {
        public function __construct(public $b5, public $b30) {}

        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return $interval === '5min' ? $this->b5 : $this->b30;
        }
    };

    $calendarProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            return [
                [
                    'title' => 'Test Event',
                    'country' => 'EUR',
                    'date_utc' => '2024-01-15T14:00:00+00:00',
                    'impact' => 'High',
                ],
            ];
        }

        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    $contextBuilder = new ContextBuilder($updater, new CalendarLookup($calendarProvider));

    // Setup cache data using correct API response format and cache key
    $testDate = '2024-01-15';
    $testPair = 'EUR/USD';
    $cacheKey = NewsCacheKeyStrategy::statKey($testPair, $testDate);

    $cachedData = [
        'pair' => 'EUR-USD',
        'raw_score' => 0.5,  // Positive score should result in 'buy' direction
        'strength' => 0.7,
        'counts' => ['pos' => 5, 'neg' => 2, 'neu' => 1],
        'date' => $testDate,
    ];

    Cache::put($cacheKey, $cachedData, 3600);

    // Build context with cached news data
    $context = $contextBuilder->build($testPair, $now);

    // Verify news data comes from cache with direction computed from raw_score
    expect($context['news']['direction'])->toBe('buy');  // raw_score > 0 = 'buy'
    expect($context['news']['strength'])->toBe(0.7);
    expect($context['news']['counts']['pos'])->toBe(5);
    expect($context['news']['raw_score'])->toBe(0.5);
    expect($context['news']['date'])->toBe($testDate);
});

test('ContextBuilder handles missing cache data gracefully', function () {
    $now = new \DateTimeImmutable('2024-01-15 12:00:00', new \DateTimeZone('UTC'));

    // Build deterministic bars for 5m and 30m with enough history
    $bars5 = [];
    for ($i = 0; $i < 100; $i++) {
        $ts = $now->sub(new \DateInterval('PT'.(5 * (100 - $i)).'M'));
        $bars5[] = new Bar($ts, 1.0950, 1.0955, 1.0945, 1.0952, 100);
    }

    $bars30 = [];
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->sub(new \DateInterval('PT'.(30 * (50 - $i)).'M'));
        $bars30[] = new Bar($ts, 1.0950, 1.0955, 1.0945, 1.0952, 100);
    }

    $updater = new class($bars5, $bars30) implements \App\Application\Candles\CandleUpdaterContract
    {
        public function __construct(public $b5, public $b30) {}

        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return $interval === '5min' ? $this->b5 : $this->b30;
        }
    };

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

    $contextBuilder = new ContextBuilder($updater, new CalendarLookup($calendarProvider));

    // Clear any existing cache data
    Cache::flush();

    // Build context without any cached data
    $testDate = '2024-01-15';
    $testPair = 'EUR/USD';
    $context = $contextBuilder->build($testPair, $now);

    // Verify it uses neutral defaults when no cache data
    expect($context['news']['direction'])->toBe('neutral');
    expect($context['news']['strength'])->toBe(0.0);
    expect($context['news']['counts']['pos'])->toBe(0);
    expect($context['news']['raw_score'])->toBe(0.0);
    expect($context['news']['date'])->toBe($testDate);
});
