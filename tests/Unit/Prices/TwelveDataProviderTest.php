<?php

use App\Services\Prices\TwelveDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('returns array<Bar> oldest->newest with numeric fields', function () {
    Http::fake([
        'api.twelvedata.com/*' => Http::response([
            'meta' => ['symbol' => 'EURUSD'],
            'values' => [
                ['datetime' => '2025-09-12 13:05:00', 'open' => '1.105', 'high' => '1.115', 'low' => '1.095', 'close' => '1.11', 'volume' => '1200'],
                ['datetime' => '2025-09-12 13:00:00', 'open' => '1.10', 'high' => '1.11', 'low' => '1.09', 'close' => '1.105', 'volume' => '1000'],
            ],
        ], 200),
    ]);

    $provider = new TwelveDataProvider('fake-key', 'https://api.twelvedata.com');
    $bars = $provider->getCandles('EURUSD', ['interval' => '5min', 'outputsize' => 2]);

    expect($bars)->toBeArray();
    expect(count($bars))->toBe(2);
    // oldest -> newest
    expect($bars[0]->ts->format('Y-m-d H:i:s'))->toBe('2025-09-12 13:00:00');
    expect(is_float($bars[0]->open))->toBeTrue();
    expect(is_float($bars[0]->high))->toBeTrue();
    expect(is_float($bars[0]->low))->toBeTrue();
    expect(is_float($bars[0]->close))->toBeTrue();
    expect(is_float($bars[0]->volume))->toBeTrue();
});

it('throws RuntimeException when response missing values', function () {
    // Response missing 'values' key
    Http::fake([
        'api.twelvedata.com/*' => Http::response(['meta' => ['symbol' => 'EURUSD']], 200),
    ]);

    $provider = new TwelveDataProvider('fake-key', 'https://api.twelvedata.com');

    expect(fn () => $provider->getCandles('EURUSD', ['interval' => '5min']))->toThrow(RuntimeException::class);
});
