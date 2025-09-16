<?php

declare(strict_types=1);

use App\Models\Candle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('can create a candle with all fields', function () {
    $candle = Candle::create([
        'pair' => 'EURUSD',
        'interval' => '5min',
        'timestamp' => '2022-06-15 14:30:00',
        'open' => 1.12345,
        'high' => 1.12456,
        'low' => 1.12234,
        'close' => 1.12387,
        'volume' => 1500000,
    ]);

    expect($candle->pair)->toBe('EURUSD');
    expect($candle->interval)->toBe('5min');
    expect($candle->timestamp)->toBeInstanceOf(Carbon::class);
    expect($candle->timestamp->format('Y-m-d H:i:s'))->toBe('2022-06-15 14:30:00');
    expect((float) $candle->open)->toBe(1.12345);
    expect((float) $candle->high)->toBe(1.12456);
    expect((float) $candle->low)->toBe(1.12234);
    expect((float) $candle->close)->toBe(1.12387);
    expect($candle->volume)->toBe(1500000);
});

it('can filter by pair using scope', function () {
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => now(), 'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.15]);
    Candle::create(['pair' => 'GBPUSD', 'interval' => '5min', 'timestamp' => now(), 'open' => 1.3, 'high' => 1.4, 'low' => 1.2, 'close' => 1.35]);

    $eurCandles = Candle::forPair('EURUSD')->get();
    $gbpCandles = Candle::forPair('GBPUSD')->get();

    expect($eurCandles->count())->toBe(1);
    expect($gbpCandles->count())->toBe(1);
    expect($eurCandles->first()->pair)->toBe('EURUSD');
    expect($gbpCandles->first()->pair)->toBe('GBPUSD');
});

it('can filter by interval using scope', function () {
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => now(), 'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.15]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '30min', 'timestamp' => now(), 'open' => 1.3, 'high' => 1.4, 'low' => 1.2, 'close' => 1.35]);

    $fiveMinCandles = Candle::forInterval('5min')->get();
    $thirtyMinCandles = Candle::forInterval('30min')->get();

    expect($fiveMinCandles->count())->toBe(1);
    expect($thirtyMinCandles->count())->toBe(1);
    expect($fiveMinCandles->first()->interval)->toBe('5min');
    expect($thirtyMinCandles->first()->interval)->toBe('30min');
});

it('can filter by date range using scope', function () {
    $startDate = Carbon::create(2022, 1, 1);
    $endDate = Carbon::create(2022, 1, 31);

    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2021-12-31 23:00:00', 'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.15]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.2, 'high' => 1.3, 'low' => 1.1, 'close' => 1.25]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2022-02-01 08:00:00', 'open' => 1.3, 'high' => 1.4, 'low' => 1.2, 'close' => 1.35]);

    $candlesInRange = Candle::betweenDates($startDate, $endDate)->get();

    expect($candlesInRange->count())->toBe(1);
    expect($candlesInRange->first()->timestamp->format('Y-m-d'))->toBe('2022-01-15');
});

it('can combine scopes', function () {
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.15]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '30min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.2, 'high' => 1.3, 'low' => 1.1, 'close' => 1.25]);
    Candle::create(['pair' => 'GBPUSD', 'interval' => '5min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.3, 'high' => 1.4, 'low' => 1.2, 'close' => 1.35]);

    $specificCandles = Candle::forPair('EURUSD')->forInterval('5min')->get();

    expect($specificCandles->count())->toBe(1);
    expect($specificCandles->first()->pair)->toBe('EURUSD');
    expect($specificCandles->first()->interval)->toBe('5min');
});

it('can get latest candle for pair and interval', function () {
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.1, 'high' => 1.2, 'low' => 1.0, 'close' => 1.15]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '5min', 'timestamp' => '2022-01-15 14:35:00', 'open' => 1.15, 'high' => 1.25, 'low' => 1.1, 'close' => 1.2]);
    Candle::create(['pair' => 'EURUSD', 'interval' => '30min', 'timestamp' => '2022-01-15 14:30:00', 'open' => 1.2, 'high' => 1.3, 'low' => 1.1, 'close' => 1.25]);

    $latestFiveMin = Candle::getLatestFor('EURUSD', '5min');
    $latestThirtyMin = Candle::getLatestFor('EURUSD', '30min');
    $nonExistent = Candle::getLatestFor('GBPUSD', '5min');

    expect($latestFiveMin)->toBeInstanceOf(Candle::class);
    expect($latestFiveMin->timestamp->format('Y-m-d H:i:s'))->toBe('2022-01-15 14:35:00');
    expect($latestThirtyMin->timestamp->format('Y-m-d H:i:s'))->toBe('2022-01-15 14:30:00');
    expect($nonExistent)->toBeNull();
});

it('enforces unique constraint on pair, interval, timestamp', function () {
    Candle::create([
        'pair' => 'EURUSD',
        'interval' => '5min',
        'timestamp' => '2022-01-15 14:30:00',
        'open' => 1.1,
        'high' => 1.2,
        'low' => 1.0,
        'close' => 1.15,
        'volume' => 1000000,
    ]);

    // This should throw a database constraint error
    expect(fn () => Candle::create([
        'pair' => 'EURUSD',
        'interval' => '5min',
        'timestamp' => '2022-01-15 14:30:00', // Same timestamp
        'open' => 1.2,
        'high' => 1.3,
        'low' => 1.1,
        'close' => 1.25,
        'volume' => 1200000,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows null volume', function () {
    $candle = Candle::create([
        'pair' => 'EURUSD',
        'interval' => '5min',
        'timestamp' => '2022-01-15 14:30:00',
        'open' => 1.1,
        'high' => 1.2,
        'low' => 1.0,
        'close' => 1.15,
        // volume not provided
    ]);

    expect($candle->volume)->toBeNull();
});

it('casts decimal fields correctly', function () {
    $candle = Candle::create([
        'pair' => 'EURUSD',
        'interval' => '5min',
        'timestamp' => '2022-01-15 14:30:00',
        'open' => '1.12345',
        'high' => '1.12456',
        'low' => '1.12234',
        'close' => '1.12387',
        'volume' => '1500000',
    ]);

    // Should be cast to decimal with 5 decimal places
    expect($candle->open)->toBeString();
    expect((float) $candle->open)->toBe(1.12345);
    expect($candle->volume)->toBeInt();
    expect($candle->volume)->toBe(1500000);
});
