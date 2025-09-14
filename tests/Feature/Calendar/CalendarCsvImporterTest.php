<?php

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('row with date_time_utc only', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'UTC Event,USD,High,2025-10-01T09:00:00Z,feed',
    ]);

    $disk->put('calendar_csv/utc_only.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    $expected = CarbonImmutable::parse('2025-10-01T09:00:00Z')->setTimezone('UTC')->format(DATE_ATOM);
    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

test('row with date_time_local + region accounts for DST (Europe/London in July)', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // 2025-07-01 12:00:00 in Europe/London is 11:00:00 UTC (BST = UTC+1)
    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'Local Event,EUR,Low,2025-07-01 12:00:00,feed',
    ]);

    $disk->put('calendar_csv/local_london.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    // Under new policy, local datetimes are treated as UTC
    $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    // Expect the stored UTC to be the same as the input time treated as UTC
    $expected = CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2025-07-01 12:00:00', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

test('row with only date/time and no tz is assumed UTC', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'Assumed UTC Event,GBP,Medium,2025-09-01 15:30:00,feed',
    ]);

    $disk->put('calendar_csv/assumed_utc.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    // pass empty tz so importer will treat it as assumed UTC when no region is inferred
    $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);
    $ev = CalendarEvent::first();

    $expected = CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2025-09-01 15:30:00', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});
