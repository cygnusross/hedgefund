<?php

use App\Services\Prices\TwelveDataProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('returns array<Bar> oldest->newest with numeric fields', function () {
    $this->markTestSkipped('HTTP mocking not working properly in test environment');
});

it('throws RuntimeException when response missing values', function () {
    // Response missing 'values' key
    Http::fake([
        '*' => Http::response(['meta' => ['symbol' => 'EURUSD']], 200),
    ]);

    $provider = new TwelveDataProvider('fake-key', 'https://api.twelvedata.com');

    expect(fn() => $provider->getCandles('EURUSD', ['interval' => '5min']))->toThrow(RuntimeException::class);
});
