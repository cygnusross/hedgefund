<?php

use App\Models\CalendarEvent;
use App\Models\Market;
use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('expands country All into one event per market currency', function () {
    Artisan::call('migrate');
    CalendarEvent::query()->delete();
    Market::query()->delete();

    // Create markets with currencies
    Market::create(['name' => 'EURUSD', 'symbol' => 'EUR/USD', 'epic' => 'EURUSD', 'currencies' => ['EUR', 'USD'], 'is_active' => 1]);
    Market::create(['name' => 'GBPUSD', 'symbol' => 'GBP/USD', 'epic' => 'GBPUSD', 'currencies' => ['GBP', 'USD'], 'is_active' => 1]);

    Http::fake([
        '*' => Http::response([
            ['title' => 'OPEC-JMMC Meetings', 'country' => 'All', 'date' => '2025-09-07T05:15:00-04:00', 'impact' => 'Medium', 'source' => 'test'],
        ], 200),
    ]);

    $prov = new EconomicCalendarProvider;
    $items = $prov->getCalendar(true);
    expect(count($items))->toBe(1);

    $prov->ingest($items);

    // Unique currencies from markets: EUR, USD, GBP => 3 events
    expect(CalendarEvent::count())->toBe(3);

    $currencies = CalendarEvent::pluck('currency')->sort()->values()->all();
    expect($currencies)->toContain('EUR');
    expect($currencies)->toContain('USD');
    expect($currencies)->toContain('GBP');
});
