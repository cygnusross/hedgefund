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
    // strength uses (score + 1.5) / 3.0 -> for 0.3 this is (1.8)/3 = 0.6
    expect($stat['strength'])->toBeFloat()->toBe(0.6);
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
