<?php

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\CalendarCsvTestHelpers;

uses(RefreshDatabase::class, CalendarCsvTestHelpers::class);

test('row with date_time_utc only', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('utc_only.csv', [
        ['UTC Event', 'USD', 'High', '2025-10-01T09:00:00Z', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    $this->assertCalendarEventTimeUtc($ev, '2025-10-01T09:00:00Z');
});

test('row with date_time_local + region accounts for DST (Europe/London in July)', function () {
    $this->setupCalendarCsvTest();

    // 2025-07-01 12:00:00 in Europe/London is 11:00:00 UTC (BST = UTC+1)
    $this->createSimpleCalendarCsv('local_london.csv', [
        ['Local Event', 'EUR', 'Low', '2025-07-01 12:00:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    // Under new policy, local datetimes are treated as UTC
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    // Expect the stored UTC to be the same as the input time treated as UTC
    $expected = CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2025-07-01 12:00:00', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

test('row with only date/time and no tz is assumed UTC', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('assumed_utc.csv', [
        ['Assumed UTC Event', 'GBP', 'Medium', '2025-09-01 15:30:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    // pass empty tz so importer will treat it as assumed UTC when no region is inferred
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    $expected = CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2025-09-01 15:30:00', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});
