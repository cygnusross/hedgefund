<?php

use App\Application\Calendar\CalendarLookup;
use App\Models\CalendarEvent;

it('computes next_high and within_blackout from DB events', function () {
    // Ensure migrations
    $this->artisan('migrate')->run();

    // Clean DB
    CalendarEvent::query()->delete();

    // Create events: one high-impact in 30 minutes, one later
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $in30 = $now->add(new \DateInterval('PT30M'));
    $in120 = $now->add(new \DateInterval('PT120M'));

    CalendarEvent::create([
        'title' => 'Soon High',
        'currency' => 'USD',
        'impact' => 'High',
        'event_time_utc' => $in30->format(DATE_ATOM),
        'source' => 'test',
        'hash' => CalendarEvent::makeHash('Soon High', 'USD', $in30),
    ]);

    CalendarEvent::create([
        'title' => 'Later High',
        'currency' => 'USD',
        'impact' => 'High',
        'event_time_utc' => $in120->format(DATE_ATOM),
        'source' => 'test',
        'hash' => CalendarEvent::makeHash('Later High', 'USD', $in120),
    ]);

    $lookup = new CalendarLookup(app('App\Services\Economic\EconomicCalendarProvider'));

    $summary = $lookup->summary('EUR/USD', $now);

    expect($summary)->not->toBeNull();
    expect($summary['next_high']['minutes_to'])->toBe(30);
    expect($summary['within_blackout'])->toBe(true);
});
