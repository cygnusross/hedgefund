<?php

use App\Models\CalendarEvent;
use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('ingests provider items into calendar_events', function () {
    // Fake HTTP response to provider URL
    Http::fake([
        '*' => Http::response([
            ['title' => 'Test Event A', 'country' => 'USD', 'date' => '2025-09-20T12:00:00Z', 'impact' => 'High', 'source' => 'test'],
            ['title' => 'Test Event B', 'country' => 'EUR', 'date' => '2025-09-21T09:00:00Z', 'impact' => 'High', 'source' => 'test'],
        ], 200),
    ]);

    // Ensure DB migrations have run for test and DB is clean
    Artisan::call('migrate');
    CalendarEvent::query()->delete();

    $prov = new EconomicCalendarProvider;
    $items = $prov->getCalendar(true);

    expect(count($items))->toBe(2);

    // Ingest
    $prov->ingest($items);

    expect(CalendarEvent::count())->toBe(2);

    $evt = CalendarEvent::firstWhere('title', 'Test Event A');
    expect($evt)->not->toBeNull();
    expect($evt->impact)->toBe('High');
});
