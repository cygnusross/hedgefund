<?php

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\CalendarCsvTestHelpers;

uses(RefreshDatabase::class, CalendarCsvTestHelpers::class);

test('imports one file, creates rows, times converted to UTC, no duplicates on second run', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('test.csv', [
        ['Test Event', 'EUR', 'High', '2025-09-07T05:15:00-04:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    // allow Low impacts for this test
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);

    $ev = CalendarEvent::first();
    expect($ev->title)->toBe('Test Event');

    $expected = CarbonImmutable::parse('2025-09-07T05:15:00-04:00')->setTimezone('UTC')->format(DATE_ATOM);
    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);

    // run again, should not create duplicate
    // allow Low impacts for this test
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');
    expect(CalendarEvent::count())->toBe(1);
});

test('row with ISO offset time converts correctly', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('offset.csv', [
        ['Offset Event', 'USD', 'Low', '2025-12-01T12:00:00+02:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    // allow Low impacts for this test
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    $ev = CalendarEvent::first();
    $expected = CarbonImmutable::parse('2025-12-01T12:00:00+02:00')->setTimezone('UTC')->format(DATE_ATOM);
    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

test('--dry-run changes nothing in DB', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('dryrun.csv', [
        ['DryRun Event', 'GBP', 'Medium', '2025-10-01 09:00:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $importer->importDirectory($this->getCalendarCsvPath(), true, false, 'Medium');

    expect(CalendarEvent::count())->toBe(0);
});

test('--keep-files moves to processed/', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('keep.csv', [
        ['Keep File Event', 'EUR', 'Low', '2025-11-01 10:00:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $importer->importDirectory($this->getCalendarCsvPath(), false, true, 'Medium');

    expect(Storage::disk('local')->exists('calendar_csv/processed/keep.csv'))->toBeTrue();
});

test('bad row goes to failed/ and error log is created', function () {
    $this->setupCalendarCsvTest();

    // missing title and date -> should be treated as bad
    $this->createSimpleCalendarCsv('bad.csv', [
        ['', '', 'Low', '', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Medium');

    expect(Storage::disk('local')->exists('calendar_csv/failed/bad.csv'))->toBeTrue();
    expect(Storage::disk('local')->exists('calendar_csv/failed/bad.csv.errors.txt'))->toBeTrue();
});
