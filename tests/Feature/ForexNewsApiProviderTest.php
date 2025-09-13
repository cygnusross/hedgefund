<?php

use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Facades\Http;

it('maps forexnewsapi response and respects trial limit', function () {
    // Sample /stat API response with totals for EUR-USD
    $sample = [
        'total' => [
            'EUR-USD' => [
                'Total Positive' => 2,
                'Total Negative' => 0,
                'Total Neutral' => 1,
                'Sentiment Score' => 0.66,
            ],
        ],
    ];

    Http::fake(['*' => Http::response($sample, 200)]);

    $prov = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);
    $stat = $prov->fetchStat('EUR/USD', '2025-09-12', true);

    expect(is_array($stat))->toBeTrue();
    expect($stat['pos'])->toBe(2);
    expect($stat['score'])->toBe(0.66);
});
