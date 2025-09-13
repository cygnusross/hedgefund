<?php

use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('parses latest per-date bucket when totals absent', function () {
    $sample = [
        'data' => [
            '2025-09-11' => [
                'EUR-USD' => [
                    'Positive' => 1,
                    'Negative' => 0,
                    'Neutral' => 0,
                    'sentiment_score' => 0.1,
                ],
            ],
            '2025-09-12' => [
                'EUR-USD' => [
                    'Positive' => 2,
                    'Negative' => 1,
                    'Neutral' => 0,
                    'sentiment_score' => 0.33,
                ],
            ],
        ],
    ];

    Http::fake(['*' => Http::response($sample, 200)]);

    $prov = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $stat = $prov->fetchStat('EUR/USD', '2025-09-12', true);

    expect($stat)->toBeArray()->toHaveKeys(['pair', 'date', 'pos', 'neg', 'neu', 'score']);
    expect($stat['pos'])->toBe(2);
    expect($stat['neg'])->toBe(1);
    expect($stat['score'])->toBe(0.33);
});

it('falls back to alternate total key names when present', function () {
    $sample = [
        'total' => [
            'EUR-USD' => [
                'Positive' => 5,
                'Negative' => 0,
                'Neutral' => 0,
                'sentiment_score' => 0.9,
            ],
        ],
    ];

    Http::fake(['*' => Http::response($sample, 200)]);

    $prov = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $stat = $prov->fetchStat('EUR/USD', '2025-09-12', false);

    expect($stat['pos'])->toBe(5);
    expect($stat['score'])->toBe(0.9);
});

it('returns neutral stats on HTTP error or invalid JSON', function () {
    // HTTP error
    Http::fakeSequence()->push('', 500);

    $prov = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $stat = $prov->fetchStat('EUR/USD', '2025-09-12', true);
    expect($stat['pos'])->toBe(0);
    expect($stat['neg'])->toBe(0);
    expect($stat['neu'])->toBe(0);
    expect($stat['score'])->toBe(0.0);

    // invalid JSON
    Http::fake(['*' => Http::response('not-json', 200)]);
    $stat2 = $prov->fetchStat('EUR/USD', '2025-09-12', true);
    expect($stat2['pos'])->toBe(0);
    expect($stat2['neg'])->toBe(0);
    expect($stat2['neu'])->toBe(0);
    expect($stat2['score'])->toBe(0.0);
});
