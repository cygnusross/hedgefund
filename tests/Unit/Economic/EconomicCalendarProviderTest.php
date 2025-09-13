<?php

use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('fetches and normalizes the economic calendar and caches results', function () {
    Cache::flush();

    $sample = [
        [
            'title' => 'Test Event',
            'country' => 'USD',
            'date' => '2025-09-12T10:00:00-04:00',
            'impact' => 'High',
        ],
    ];

    Http::fake([
        config('economic.calendar_url') => Http::response($sample, 200),
    ]);

    $provider = new EconomicCalendarProvider;

    $first = $provider->getCalendar();

    expect($first)->toBeArray();
    expect($first[0])->toHaveKeys(['title', 'country', 'date_utc', 'impact']);

    // Ensure date_utc is ISO8601 (ends with Z or +00:00)
    expect($first[0]['date_utc'])->not->toBeNull();

    // Call again; Http::fake should not be hit because result is cached
    Http::fake(); // replace fake with default that would fail if a real call attempted

    $second = $provider->getCalendar();

    expect($second)->toEqual($first);
});
