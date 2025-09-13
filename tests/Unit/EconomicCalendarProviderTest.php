<?php

use App\Models\CalendarEvent;
use App\Models\Market;
use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Ensure clean DB
    // Run migrations and start from a clean slate
    Artisan::call('migrate:fresh');
    CalendarEvent::query()->delete();
    Market::query()->delete();
});

it('expands All into unique currencies from markets', function () {
    Market::create(['name' => 'EURUSD', 'symbol' => 'EUR/USD', 'epic' => 'EURUSD', 'currencies' => ['EUR', 'USD'], 'is_active' => 1]);
    Market::create(['name' => 'GBPUSD', 'symbol' => 'GBP/USD', 'epic' => 'GBPUSD', 'currencies' => ['GBP', 'USD'], 'is_active' => 1]);

    $prov = new EconomicCalendarProvider;
    $items = [[
        'title' => 'OPEC-JMMC Meetings',
        'country' => 'All',
        'date_utc' => '2025-09-07T09:15:00+00:00',
        'impact' => 'Medium',
        'source' => 'test',
    ]];

    $prov->ingest($items);

    expect(CalendarEvent::count())->toBe(3);
    $currencies = CalendarEvent::pluck('currency')->sort()->values()->all();
    expect($currencies)->toContain('EUR');
    expect($currencies)->toContain('USD');
    expect($currencies)->toContain('GBP');
});

it('treats country as currency code directly', function () {
    CalendarEvent::query()->delete();

    $prov = new EconomicCalendarProvider;
    $items = [[
        'title' => 'BOJ Policy',
        'country' => 'JPY',
        'date_utc' => '2025-09-07T09:15:00+00:00',
        'impact' => 'High',
        'source' => 'test',
    ]];

    $prov->ingest($items);

    expect(CalendarEvent::count())->toBe(1);
    expect(CalendarEvent::first()->currency)->toBe('JPY');
});

it('skips Low impact items', function () {
    CalendarEvent::query()->delete();

    $prov = new EconomicCalendarProvider;
    $items = [[
        'title' => 'Minor Data',
        'country' => 'USD',
        'date_utc' => '2025-09-07T09:15:00+00:00',
        'impact' => 'Low',
        'source' => 'test',
    ]];

    $prov->ingest($items);

    expect(CalendarEvent::count())->toBe(0);
});

it('skips malformed date', function () {
    CalendarEvent::query()->delete();

    $prov = new EconomicCalendarProvider;
    $items = [[
        'title' => 'Bad Date',
        'country' => 'USD',
        'date_utc' => null,
        'impact' => 'High',
        'source' => 'test',
    ]];

    $prov->ingest($items);

    expect(CalendarEvent::count())->toBe(0);
});
