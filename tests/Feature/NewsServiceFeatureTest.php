<?php

declare(strict_types=1);

use App\Application\News\NewsData;
use App\Application\News\NewsService;
use App\Models\NewsStat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->service = new NewsService();
});

it('falls back to database when cache misses and rebuilds cache', function () {
    $pair = 'EUR-USD';
    $date = Carbon::today()->toDateString();

    // Create database record
    $newsStat = NewsStat::factory()->create([
        'pair_norm' => 'EUR-USD',
        'stat_date' => $date,
        'pos' => 60,
        'neg' => 30,
        'neu' => 10,
        'raw_score' => 0.3,
        'strength' => 0.6,
        'source' => 'forexnewsapi',
    ]);

    $result = $this->service->getNews($pair, $date);

    expect($result)->toBeInstanceOf(NewsData::class)
        ->and($result->rawScore)->toBe(0.3)
        ->and($result->strength)->toBe(0.6)
        ->and($result->counts)->toBe(['pos' => 60, 'neg' => 30, 'neu' => 10])
        ->and($result->pair)->toBe($pair)
        ->and($result->date)->toBe($date);

    // Verify cache was rebuilt - second call should hit cache
    $secondResult = $this->service->getNews($pair, $date);
    expect($secondResult->rawScore)->toBe(0.3);
});

it('returns neutral data when no database record exists', function () {
    $pair = 'EUR-USD';
    $date = Carbon::today()->toDateString();

    $result = $this->service->getNews($pair, $date);

    expect($result)->toBeInstanceOf(NewsData::class)
        ->and($result->rawScore)->toBe(0.0)
        ->and($result->strength)->toBe(0.0)
        ->and($result->counts)->toBe(['pos' => 0, 'neg' => 0, 'neu' => 0])
        ->and($result->pair)->toBe($pair)
        ->and($result->date)->toBe($date);
});

it('handles today parameter correctly', function () {
    $pair = 'EUR-USD';
    $expectedDate = now('UTC')->format('Y-m-d');

    NewsStat::factory()->create([
        'pair_norm' => 'EUR-USD',
        'stat_date' => $expectedDate,
        'pos' => 40,
        'neg' => 35,
        'neu' => 25,
        'raw_score' => -0.2,
        'strength' => 0.4,
    ]);

    $result = $this->service->getNews($pair, 'today');

    expect($result)->toBeInstanceOf(NewsData::class)
        ->and($result->date)->toBe($expectedDate)
        ->and($result->rawScore)->toBe(-0.2);
});
