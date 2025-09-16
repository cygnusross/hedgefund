<?php

declare(strict_types=1);

use App\Application\News\NewsCacheKeyStrategy;
use App\Application\News\NewsData;
use App\Application\News\NewsService;
use Carbon\Carbon;
use Illuminate\Cache\Repository;

use function Pest\Laravel\mock;

beforeEach(function () {
    $this->cache = mock(Repository::class);
    $this->service = new NewsService($this->cache);
});

it('returns cached data when available', function () {
    $pair = 'EUR-USD';
    $date = Carbon::today()->toDateString();
    $cacheKey = NewsCacheKeyStrategy::statKey($pair, $date);
    $cachedPayload = [
        'raw_score' => 0.55,
        'strength' => 0.8,
        'counts' => ['pos' => 75, 'neg' => 20, 'neu' => 5],
        'pair' => 'EUR-USD',
        'date' => $date,
        'source' => 'forexnewsapi',
    ];

    $this->cache->shouldReceive('get')
        ->once()
        ->with($cacheKey)
        ->andReturn($cachedPayload);

    $result = $this->service->getNews($pair, $date);

    expect($result)->toBeInstanceOf(NewsData::class)
        ->and($result->rawScore)->toBe(0.55)
        ->and($result->strength)->toBe(0.8)
        ->and($result->counts)->toBe(['pos' => 75, 'neg' => 20, 'neu' => 5])
        ->and($result->pair)->toBe('EUR-USD')
        ->and($result->date)->toBe($date);
});

it('normalizes pair format correctly', function () {
    $pair = 'EUR/USD'; // Input with slash
    $normalizedPair = 'EUR-USD';
    $date = Carbon::today()->toDateString();
    $cacheKey = NewsCacheKeyStrategy::statKey($normalizedPair, $date);
    $cachedPayload = [
        'raw_score' => 0.3,
        'strength' => 0.6,
        'counts' => ['pos' => 50, 'neg' => 30, 'neu' => 20],
        'pair' => 'EUR/USD', // Original pair format preserved
        'date' => $date,
        'source' => 'forexnewsapi',
    ];

    $this->cache->shouldReceive('get')
        ->once()
        ->with($cacheKey)
        ->andReturn($cachedPayload);

    $result = $this->service->getNews($pair, $date);

    expect($result)->toBeInstanceOf(NewsData::class)
        ->and($result->pair)->toBe('EUR/USD'); // Original format preserved
});
