<?php

use App\Application\News\NewsStatIngestor;
use App\Models\NewsStat;
use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Ensure a clean DB state
    $this->artisan('migrate:fresh');
});

it('ingests two pages for today', function () {
    // Fake provider responses for two pages
    $pair = 'EUR/USD';
    $pairNorm = str_replace('/', '-', strtoupper($pair));

    $page1 = [
        'total_pages' => 2,
        'data' => [
            '2025-09-13' => [
                $pairNorm => [
                    'Positive' => 5,
                    'Negative' => 2,
                    'Neutral' => 3,
                    'sentiment_score' => 0.5,
                ],
            ],
        ],
    ];

    $page2 = [
        'data' => [
            '2025-09-12' => [
                $pairNorm => [
                    'Positive' => 2,
                    'Negative' => 4,
                    'Neutral' => 4,
                    'sentiment_score' => -0.3,
                ],
            ],
        ],
    ];

    // Mock provider fetchStatPage
    $provider = $this->mock(ForexNewsApiProvider::class);
    $provider->shouldReceive('fetchStatPage')->with($pairNorm, 'today', 1)->andReturn($page1);
    $provider->shouldReceive('fetchStatPage')->with($pairNorm, 'today', 2)->andReturn($page2);

    $ingestor = new NewsStatIngestor($provider);

    $count = $ingestor->ingestToday($pair);

    expect($count)->toBe(2);

    $rows = NewsStat::where('pair_norm', $pairNorm)->orderBy('stat_date', 'desc')->get();
    expect($rows)->toHaveCount(2);

    $first = $rows->first();
    expect($first->stat_date->toDateString())->toBe('2025-09-13');
    // raw_score stored as provided
    expect((float) $first->raw_score)->toBe(0.5);
    // strength normalized 0..1: (0.5+1.5)/3 = 0.666666...
    expect(round((float) $first->strength, 3))->toBe(round((0.5 + 1.5) / 3.0, 3));
});

it('ingests a range with multiple pages', function () {
    $pair = 'EUR/USD';
    $pairNorm = str_replace('/', '-', strtoupper($pair));

    $page1 = [
        'total_pages' => 2,
        'data' => [
            '2025-09-11' => [
                $pairNorm => [
                    'Positive' => 1,
                    'Negative' => 1,
                    'Neutral' => 8,
                    'sentiment_score' => 0.0,
                ],
            ],
        ],
    ];

    $page2 = [
        'data' => [
            '2025-09-10' => [
                $pairNorm => [
                    'Positive' => 3,
                    'Negative' => 0,
                    'Neutral' => 7,
                    'sentiment_score' => 0.8,
                ],
            ],
            '2025-09-09' => [
                $pairNorm => [
                    'Positive' => 2,
                    'Negative' => 2,
                    'Neutral' => 6,
                    'sentiment_score' => -0.2,
                ],
            ],
        ],
    ];

    $provider = $this->mock(ForexNewsApiProvider::class);
    $from = '2025-09-09';
    $to = '2025-09-11';
    $dateParam = Carbon::parse($from)->format('mdY').'-'.Carbon::parse($to)->format('mdY');

    $provider->shouldReceive('fetchStatPage')->with($pairNorm, $dateParam, 1)->andReturn($page1);
    $provider->shouldReceive('fetchStatPage')->with($pairNorm, $dateParam, 2)->andReturn($page2);

    $ingestor = new NewsStatIngestor($provider);

    $count = $ingestor->ingestRangeDates($pair, $from, $to);

    expect($count)->toBe(3);

    $rows = NewsStat::where('pair_norm', $pairNorm)->orderBy('stat_date', 'desc')->get();
    expect($rows)->toHaveCount(3);
});
