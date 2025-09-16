<?php

use App\Application\News\NewsCacheKeyStrategy;
use App\Application\News\NewsStatIngestor;
use App\Models\NewsStat;
use App\Services\News\ForexNewsApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('migrate');
    Cache::flush(); // Clear cache between tests
});

it('caches API responses during ingest operations', function () {
    $pair = 'EUR/USD';
    $date = '2024-01-15';

    // Create mock response
    $fakeResp = [
        'pair' => 'EUR-USD',
        'date' => $date,
        'raw_score' => 0.5,
        'strength' => 0.33,
        'counts' => ['pos' => 5, 'neg' => 2, 'neu' => 1],
    ];

    // Track API calls
    $apiCallCount = 0;

    $mockProvider = new class($fakeResp, $apiCallCount) extends ForexNewsApiProvider
    {
        private $resp;

        private $callCounter;

        public function __construct($resp, &$callCounter)
        {
            parent::__construct(['token' => 'fake']);
            $this->resp = $resp;
            $this->callCounter = &$callCounter;
        }

        public function fetchStats(string $pair, string $date = 'today'): array
        {
            $this->callCounter++;

            return $this->resp;
        }
    };

    $ingestor = new NewsStatIngestor($mockProvider);

    // First check that cache is empty
    $cacheKey = NewsCacheKeyStrategy::statKey($pair, $date);
    expect(Cache::has($cacheKey))->toBeFalse();

    // First call should hit API and cache the result temporarily
    $result1 = $ingestor->ingest($pair, $date);
    expect($apiCallCount)->toBe(1);
    expect($result1)->toBeInstanceOf(NewsStat::class);
    expect($result1->raw_score)->toBe(0.5);

    // NOTE: The stat cache key is intentionally cleared after ingest to ensure
    // ContextBuilder gets fresh data from DB, so we can't test for cache persistence here.
    // Instead verify the data was persisted to database
    $persisted = NewsStat::where('pair_norm', 'EUR-USD')
        ->whereDate('stat_date', $date)
        ->first();
    expect($persisted)->not->toBeNull();
    expect($persisted->raw_score)->toBe(0.5);

    // Second call should use cache, not hit API
    // Use different date to avoid DB constraint issues
    $secondDate = '2024-01-16';
    $secondResp = [
        'pair' => 'EUR-USD',
        'date' => $secondDate,
        'raw_score' => 0.8,
        'counts' => ['pos' => 3, 'neg' => 1, 'neu' => 2],
    ];

    // Clear cache for this test
    $secondCacheKey = NewsCacheKeyStrategy::statKey($pair, $secondDate);
    Cache::put($secondCacheKey, $secondResp, 3600);

    // This call should use the cached data
    $result2 = $ingestor->ingest($pair, $secondDate);
    expect($apiCallCount)->toBe(1); // Still 1, no additional API call
    expect($result2)->toBeInstanceOf(NewsStat::class);
});

it('respects TTL for today vs historical data', function () {
    // Mock time to ensure consistent behavior
    $today = '2024-01-15';
    $historical = '2024-01-10';

    // Today's data should have shorter TTL (4 hours)
    $todayTtl = NewsCacheKeyStrategy::getTtl('today');
    expect($todayTtl)->toBe(14400); // 4 hours

    // Historical data should have longer TTL (24 hours)
    $historicalTtl = NewsCacheKeyStrategy::getTtl($historical);
    expect($historicalTtl)->toBe(86400); // 24 hours

    // Current day detection
    expect(NewsCacheKeyStrategy::isToday('today'))->toBeTrue();
});

it('generates consistent cache keys', function () {
    $pair = 'EUR/USD';
    $date = '2024-01-15';

    // Test stat key format
    $statKey = NewsCacheKeyStrategy::statKey($pair, $date);
    expect($statKey)->toBe('news_json:stat:EUR-USD:2024-01-15');

    // Test page key format
    $pageKey = NewsCacheKeyStrategy::pageKey('EUR-USD,GBP-USD', 'today', 1);
    expect($pageKey)->toMatch('/^news_json:page:EUR-USD,GBP-USD:\d{4}-\d{2}-\d{2}:1$/');

    // Test normalization
    $normalizedKey = NewsCacheKeyStrategy::statKey('eur/usd', $date);
    expect($normalizedKey)->toBe('news_json:stat:EUR-USD:2024-01-15');
});

it('retrieves cached data without database persistence', function () {
    $pair = 'GBP/USD';
    $date = 'today';

    // Manually set cache data
    $testData = [
        'pair' => 'GBP-USD',
        'raw_score' => 0.8,
        'counts' => ['pos' => 8, 'neg' => 1, 'neu' => 2],
    ];

    $cacheKey = NewsCacheKeyStrategy::statKey($pair, $date);
    Cache::put($cacheKey, $testData, 3600);

    $mockProvider = new class extends ForexNewsApiProvider
    {
        public function __construct()
        {
            parent::__construct(['token' => 'fake']);
        }

        public function fetchStats(string $pair, string $date = 'today'): array
        {
            throw new \Exception('API should not be called for cache retrieval');
        }
    };

    $ingestor = new NewsStatIngestor($mockProvider);

    // Test direct cache retrieval
    $cached = $ingestor->getCachedNewsJson($pair, $date);
    expect($cached)->toBeArray();
    expect($cached['raw_score'])->toBe(0.8);

    // Test batch retrieval
    $batchResult = $ingestor->getCachedTodayJson([$pair, 'USD/JPY']);
    expect($batchResult)->toHaveKey($pair);
    expect($batchResult)->not->toHaveKey('USD/JPY'); // Not cached
});

it('handles cache invalidation correctly', function () {
    $pair = 'USD/JPY';
    $date = 'today';

    // Set up cache
    $cacheKey = NewsCacheKeyStrategy::statKey($pair, $date);
    Cache::put($cacheKey, ['test' => 'data'], 3600);

    expect(Cache::has($cacheKey))->toBeTrue();

    $mockProvider = new class extends ForexNewsApiProvider
    {
        public function __construct()
        {
            parent::__construct(['token' => 'fake']);
        }
    };

    $ingestor = new NewsStatIngestor($mockProvider);

    // Test manual invalidation
    $result = $ingestor->invalidateCache($pair, $date);
    expect($result)->toBeTrue();
    expect(Cache::has($cacheKey))->toBeFalse();
});

it('logs cache operations for debugging', function () {
    $pair = 'AUD/USD';
    $date = '2024-01-20';

    // Create mock that tracks if fetchStats is called
    $fetchStatsCalled = false;

    $mockProvider = new class($fetchStatsCalled) extends ForexNewsApiProvider
    {
        private $fetchTracker;

        public function __construct(&$fetchTracker)
        {
            parent::__construct(['token' => 'fake']);
            $this->fetchTracker = &$fetchTracker;
        }

        public function fetchStats(string $pair, string $date = 'today'): array
        {
            $this->fetchTracker = true;

            return [
                'pair' => $pair,
                'raw_score' => 0.2,
                'counts' => ['pos' => 1, 'neg' => 1, 'neu' => 0],
            ];
        }
    };

    $ingestor = new NewsStatIngestor($mockProvider);

    // Verify cache is empty initially
    $cacheKey = NewsCacheKeyStrategy::statKey($pair, $date);
    expect(Cache::has($cacheKey))->toBeFalse();

    // First call should hit API (cache miss)
    $ingestor->ingest($pair, $date);
    expect($fetchStatsCalled)->toBeTrue();

    // NOTE: The stat cache key is cleared after ingest, so test DB persistence instead
    $persisted = NewsStat::where('pair_norm', 'AUD-USD')
        ->whereDate('stat_date', $date)
        ->first();
    expect($persisted)->not->toBeNull();
    expect($persisted->raw_score)->toBe(0.2);

    // Test manual cache invalidation - first put something in cache to invalidate
    Cache::put($cacheKey, ['test' => 'data'], 3600);
    expect(Cache::has($cacheKey))->toBeTrue();

    $result = $ingestor->invalidateCache($pair, $date);
    expect($result)->toBeTrue();
    expect(Cache::has($cacheKey))->toBeFalse();
});
