<?php

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('imports one file, creates rows, times converted to UTC, no duplicates on second run', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'Test Event,EUR,High,2025-09-07T05:15:00-04:00,feed',
    ]);

    $disk->put('calendar_csv/test.csv', $csv);

    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    // allow Low impacts for this test
    $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);

    $ev = CalendarEvent::first();
    expect($ev->title)->toBe('Test Event');

    $expected = CarbonImmutable::parse('2025-09-07T05:15:00-04:00')->setTimezone('UTC')->format(DATE_ATOM);
    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);

    // run again, should not create duplicate
    // allow Low impacts for this test
    $importer->importDirectory($path, false, false, 'Low');
    expect(CalendarEvent::count())->toBe(1);
});

test('row with ISO offset time converts correctly', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'Offset Event,USD,Low,2025-12-01T12:00:00+02:00,feed',
    ]);

    $disk->put('calendar_csv/offset.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    // allow Low impacts for this test
    $importer->importDirectory($path, false, false, 'Low');

    $ev = CalendarEvent::first();
    $expected = CarbonImmutable::parse('2025-12-01T12:00:00+02:00')->setTimezone('UTC')->format(DATE_ATOM);
    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

test('--dry-run changes nothing in DB', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'DryRun Event,GBP,Medium,2025-10-01 09:00:00,feed',
    ]);

    $disk->put('calendar_csv/dryrun.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    $importer->importDirectory($path, true, false, 'Medium');

    expect(CalendarEvent::count())->toBe(0);
});

test('--keep-files moves to processed/', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'Keep File Event,EUR,Low,2025-11-01 10:00:00,feed',
    ]);

    $disk->put('calendar_csv/keep.csv', $csv);

    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    $importer->importDirectory($path, false, true, 'Medium');

    expect(Storage::disk('local')->exists('calendar_csv/processed/keep.csv'))->toBeTrue();
});

test('bad row goes to failed/ and error log is created', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // missing title and date -> should be treated as bad
    $csv = implode("\n", [
        'title,currency,impact,date,source',
        ',,Low,,feed',
    ]);

    $disk->put('calendar_csv/bad.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(\App\Application\Calendar\CalendarCsvImporter::class);
    $importer->importDirectory($path, false, false, 'Medium');

    expect(Storage::disk('local')->exists('calendar_csv/failed/bad.csv'))->toBeTrue();
    expect(Storage::disk('local')->exists('calendar_csv/failed/bad.csv.errors.txt'))->toBeTrue();
});
