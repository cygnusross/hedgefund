<?php

declare(strict_types=1);

use App\Domain\Market\Bar;
use App\Models\Candle;
use App\Services\Prices\PriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clean the candles table
    DB::table('candles')->truncate();

    // Create a complete mock to prevent ANY real API calls
    $mockProvider = \Mockery::mock(PriceProvider::class);

    // Bind the mock in the container to replace the real service completely
    $this->app->instance(PriceProvider::class, $mockProvider);

    // Store reference so individual tests can add expectations
    $this->mockProvider = $mockProvider;
});

it('seeds historical candle data successfully', function () {
    // Mock PriceProvider to return test data - prevent any real API calls
    $mockCandles = [
        new Bar(new DateTimeImmutable('2022-01-01 08:00:00', new DateTimeZone('UTC')), 1.2150, 1.2200, 1.2100, 1.2180, 1000000),
        new Bar(new DateTimeImmutable('2022-01-01 08:05:00', new DateTimeZone('UTC')), 1.2180, 1.2250, 1.2150, 1.2220, 1200000),
    ];

    // Command makes many chunked API calls for 3 years of data - allow many calls
    $this->mockProvider->shouldReceive('getCandles')
        ->zeroOrMoreTimes() // Allow any number of calls for chunked data fetching
        ->with('EUR/USD', \Mockery::on(function ($params) {
            return in_array($params['interval'], ['5min', '30min']) &&
                $params['outputsize'] === 5000 &&
                isset($params['start_date']) &&
                isset($params['end_date']) &&
                $params['timezone'] === 'UTC';
        }))
        ->andReturn($mockCandles);

    $this->artisan('candles:seed-history "EUR/USD" --no-rate-limit')
        ->expectsOutput('Seeding 3 years of historical candle data for EUR/USD (5min and 30min intervals)...')
        ->expectsOutput('Processing 5min interval...')
        ->expectsOutput('Processing 30min interval...')
        ->assertSuccessful();

    // Verify candles were inserted for both intervals
    expect(Candle::count())->toBeGreaterThan(0);
    expect(Candle::where('interval', '5min')->count())->toBeGreaterThan(0);
    expect(Candle::where('interval', '30min')->count())->toBeGreaterThan(0);

    $firstCandle = Candle::where('pair', 'EUR/USD')
        ->orderBy('timestamp')
        ->first();

    expect($firstCandle->pair)->toBe('EUR/USD');
});

it('skips existing candles for idempotency', function () {
    // Create existing candles for both intervals
    Candle::create([
        'pair' => 'EUR/USD',
        'interval' => '5min',
        'timestamp' => '2022-01-01 08:00:00',
        'open' => 1.2150,
        'high' => 1.2200,
        'low' => 1.2100,
        'close' => 1.2180,
        'volume' => 1000000,
    ]);

    Candle::create([
        'pair' => 'EUR/USD',
        'interval' => '30min',
        'timestamp' => '2022-01-01 08:00:00',
        'open' => 1.2150,
        'high' => 1.2200,
        'low' => 1.2100,
        'close' => 1.2180,
        'volume' => 5000000,
    ]);

    // Mock same data - allow many chunked calls
    $mockCandles = [
        new Bar(new DateTimeImmutable('2022-01-01 08:00:00', new DateTimeZone('UTC')), 1.2150, 1.2200, 1.2100, 1.2180, 1000000),
    ];

    $this->mockProvider->shouldReceive('getCandles')
        ->zeroOrMoreTimes() // Allow many chunked calls
        ->andReturn($mockCandles);

    $this->artisan('candles:seed-history EUR/USD --no-rate-limit')
        ->assertSuccessful();

    // Should test upsert functionality
    $duplicateCount5min = Candle::where('pair', 'EUR/USD')
        ->where('interval', '5min')
        ->where('timestamp', '2022-01-01 08:00:00')
        ->count();

    $duplicateCount30min = Candle::where('pair', 'EUR/USD')
        ->where('interval', '30min')
        ->where('timestamp', '2022-01-01 08:00:00')
        ->count();

    expect($duplicateCount5min)->toBe(1); // Should still only have one instance
    expect($duplicateCount30min)->toBe(1); // Should still only have one instance
});

it('handles empty response from provider', function () {
    $this->mockProvider->shouldReceive('getCandles')
        ->zeroOrMoreTimes() // Allow many chunked calls
        ->andReturn([]);

    // Should still succeed but with 0 inserted
    $this->artisan('candles:seed-history EUR/USD --no-rate-limit')
        ->assertSuccessful();

    expect(Candle::count())->toBe(0);
});

it('handles provider exceptions gracefully', function () {
    $this->mockProvider->shouldReceive('getCandles')
        ->once() // Will fail on first call, so only once
        ->andThrow(new RuntimeException('TwelveData API error'));

    Log::shouldReceive('error')->once()->with('Historical candle seeding failed', \Mockery::type('array'));

    $this->artisan('candles:seed-history EUR/USD --no-rate-limit')
        ->assertFailed();
});

it('logs seeding results for both intervals', function () {
    $mockCandles = [
        new Bar(new DateTimeImmutable('2022-01-01 08:00:00', new DateTimeZone('UTC')), 1.2150, 1.2200, 1.2100, 1.2180, 1000000),
    ];

    $this->mockProvider->shouldReceive('getCandles')
        ->zeroOrMoreTimes() // Allow many chunked calls
        ->andReturn($mockCandles);

    // Expect logs for both intervals
    Log::shouldReceive('info')->twice()->with('Historical candle seeding completed', \Mockery::on(function ($data) {
        return $data['pair'] === 'EUR/USD' &&
            in_array($data['interval'], ['5min', '30min']) &&
            $data['period'] === '3 years' &&
            $data['inserted'] >= 0;
    }));

    $this->artisan('candles:seed-history EUR/USD --no-rate-limit')
        ->assertSuccessful();
});

it('converts Bar objects to database format correctly', function () {
    $bar = new Bar(
        new DateTimeImmutable('2022-06-15 14:30:00', new DateTimeZone('UTC')),
        1.12345,
        1.12456,
        1.12234,
        1.12387,
        1500000
    );

    $mockCandles = [$bar];

    $this->mockProvider->shouldReceive('getCandles')
        ->zeroOrMoreTimes() // Allow many chunked calls
        ->andReturn($mockCandles);

    $this->artisan('candles:seed-history EUR/USD --no-rate-limit')
        ->assertSuccessful();

    $candle = Candle::first();
    expect($candle->timestamp->format('Y-m-d H:i:s'))->toBe('2022-06-15 14:30:00');
    expect((float) $candle->open)->toBe(1.12345);
    expect((float) $candle->high)->toBe(1.12456);
    expect((float) $candle->low)->toBe(1.12234);
    expect((float) $candle->close)->toBe(1.12387);
    expect($candle->volume)->toBe(1500000);
});

it('can convert candle back to Bar object', function () {
    $candle = Candle::create([
        'pair' => 'EUR/USD',
        'interval' => '5min',
        'timestamp' => '2022-06-15 14:30:00',
        'open' => 1.12345,
        'high' => 1.12456,
        'low' => 1.12234,
        'close' => 1.12387,
        'volume' => 1500000,
    ]);

    $bar = $candle->toBar();

    expect($bar)->toBeInstanceOf(Bar::class);
    expect($bar->ts->format('Y-m-d H:i:s'))->toBe('2022-06-15 14:30:00');
    expect($bar->open)->toBe(1.12345);
    expect($bar->high)->toBe(1.12456);
    expect($bar->low)->toBe(1.12234);
    expect($bar->close)->toBe(1.12387);
    expect($bar->volume)->toBe(1500000.0);
});

it('can convert collection of candles to Bar array', function () {
    $candles = collect([
        Candle::create([
            'pair' => 'EUR/USD',
            'interval' => '5min',
            'timestamp' => '2022-06-15 14:30:00',
            'open' => 1.12345,
            'high' => 1.12456,
            'low' => 1.12234,
            'close' => 1.12387,
            'volume' => 1500000,
        ]),
        Candle::create([
            'pair' => 'EUR/USD',
            'interval' => '5min',
            'timestamp' => '2022-06-15 14:35:00',
            'open' => 1.12387,
            'high' => 1.12400,
            'low' => 1.12300,
            'close' => 1.12350,
            'volume' => 1200000,
        ]),
    ]);

    $bars = Candle::toBars($candles);

    expect($bars)->toBeArray();
    expect(count($bars))->toBe(2);
    expect($bars[0])->toBeInstanceOf(Bar::class);
    expect($bars[1])->toBeInstanceOf(Bar::class);
    expect($bars[0]->ts->format('Y-m-d H:i:s'))->toBe('2022-06-15 14:30:00');
    expect($bars[1]->ts->format('Y-m-d H:i:s'))->toBe('2022-06-15 14:35:00');
});
