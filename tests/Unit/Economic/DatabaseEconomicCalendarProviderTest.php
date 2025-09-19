<?php

declare(strict_types=1);

use App\Models\CalendarEvent;
use App\Services\Economic\DatabaseEconomicCalendarProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns calendar events from database', function () {
    $provider = new DatabaseEconomicCalendarProvider();

    CalendarEvent::factory()->create([
        'currency' => 'USD',
        'title' => 'CPI YoY',
        'impact' => 'High',
        'event_time_utc' => now('UTC')->addHours(2),
    ]);

    $items = $provider->getCalendar();

    expect($items)->toHaveCount(1);
    expect($items[0]['title'])->toBe('CPI YoY');
    expect($items[0]['currency'])->toBe('USD');
});
