<?php

use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('parses /stat responses and returns normalized stats', function () {
    $sample = [
        'data' => [
            '2025-09-12' => [
                'EUR-USD' => [
                    'Positive' => 3,
                    'Negative' => 2,
                    'Neutral' => 0,
                    'sentiment_score' => 0.3,
                ],
            ],
        ],
        'total' => [
            'EUR-USD' => [
                'Total Positive' => 3,
                'Total Negative' => 2,
                'Total Neutral' => 0,
                'Sentiment Score' => 0.3,
            ],
        ],
    ];

    Http::fake(['*' => Http::response($sample, 200)]);

    $prov = new ForexNewsApiProvider(['token' => 'fake']);
    $stat = $prov->fetchStats('EUR/USD', '2025-09-12', true);

    expect($stat)->toBeArray()->toHaveKeys(['pair', 'raw_score', 'strength', 'counts', 'date']);
    expect($stat['counts']['pos'])->toBe(3);
    expect($stat['counts']['neg'])->toBe(2);
    expect($stat['raw_score'])->toBe(0.3);
    // strength uses score / 1.5 -> for 0.3 this is 0.2 (allow small float precision margin)
    expect($stat['strength'])->toBeFloat();
    expect(abs($stat['strength'] - 0.2))->toBeLessThan(0.000001);
});
it('returns neutral stats when totals absent', function () {
    $sample = ['data' => []];
    Http::fake(['*' => Http::response($sample, 200)]);

    $prov = new ForexNewsApiProvider(['token' => 'fake']);
    $stat = $prov->fetchStats('EUR/USD', '2025-09-12', true);

    expect($stat['counts']['pos'])->toBe(0);
    expect($stat['counts']['neg'])->toBe(0);
    expect($stat['counts']['neu'])->toBe(0);
    expect($stat['raw_score'])->toBe(0.0);
    expect($stat['strength'])->toBeFloat()->toBe(0.0);
});
it('sends currencypair and date when calling fetchStat', function () {
    Http::fake(function ($request) {
        $url = $request->url();

        expect(str_contains($url, 'currencypair=EUR-USD'))->toBeTrue();
        expect(str_contains($url, 'date=2025-09-12'))->toBeTrue();

        return Http::response([], 200);
    });

    $provider = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $provider->fetchStat('EUR/USD', '2025-09-12', false);
});

it('adds cache=false when fresh is true', function () {
    Http::fake(function ($request) {
        $url = $request->url();
        expect(str_contains($url, 'cache=false'))->toBeTrue();

        return Http::response([], 200);
    });

    $provider = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $provider->fetchStat('EUR/USD', '2025-09-12', true);
});
