<?php

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\CalendarCsvTestHelpers;

uses(RefreshDatabase::class, CalendarCsvTestHelpers::class);

it('treats duplicate rows across files as update', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('file1.csv', [
        ['DupeEvent', 'USD', 'Medium', '2025-10-01 09:00:00', 'feed1'],
    ]);

    $this->createSimpleCalendarCsv('file2.csv', [
        ['DupeEvent', 'USD', 'High', '2025-10-01 09:00:00', 'feed2'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    // Import first file (both exist on disk; processed/ handling will move processed files)
    $res1 = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    // Remove first file so second import only processes file2
    Storage::disk('local')->delete('calendar_csv/file1.csv');

    $res2 = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);

    $ev = CalendarEvent::first();
    expect($ev->impact)->toBe('High');
    expect($ev->source)->toBe('feed2');
});

it('handles duplicate rows within same file without cardinality violations', function () {
    $this->setupCalendarCsvTest();

    // Create CSV with identical duplicate entries (like the Greece Unemployment case)
    $this->createSimpleCalendarCsv('duplicates.csv', [
        ['(Greece) Unemployment Rate', 'EUR', 'High', '2021-06-11 06:00:00', 'calendar_statement'],
        ['(United Kingdom) GDP Growth Rate', 'GBP', 'Medium', '2021-06-11 10:00:00', 'calendar_statement'],
        ['(Greece) Unemployment Rate', 'EUR', 'High', '2021-06-11 06:00:00', 'calendar_statement'], // Exact duplicate
        ['(Italy) Industrial Production', 'EUR', 'Medium', '2021-06-11 12:00:00', 'calendar_statement'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $result = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    // Should successfully import without cardinality violations
    expect($result['files_total'])->toBe(1);
    expect($result['files_processed'])->toBe(1);
    expect($result['created'])->toBe(3); // 3 unique events from 4 rows (1 duplicate removed)
    expect($result['errors'])->toBe(0);  // No cardinality violations

    // Verify file was processed successfully
    $fileKey = array_key_first($result['files']);
    expect($result['files'][$fileKey]['status'])->toBe('imported');
    expect($result['files'][$fileKey]['errors'])->toHaveCount(0);

    // Should only have 3 unique calendar events (duplicate removed)
    expect(CalendarEvent::count())->toBe(3);

    // Verify the correct events were created
    $events = CalendarEvent::orderBy('event_time_utc')->get();
    expect($events[0]->title)->toBe('(Greece) Unemployment Rate');
    expect($events[0]->currency)->toBe('EUR');
    expect($events[1]->title)->toBe('(United Kingdom) GDP Growth Rate');
    expect($events[2]->title)->toBe('(Italy) Industrial Production');
});

it('handles multiple different duplicates within same file', function () {
    $this->setupCalendarCsvTest();

    // Create CSV with multiple sets of duplicates
    $this->createSimpleCalendarCsv('multi_duplicates.csv', [
        ['Event A', 'USD', 'High', '2021-06-01 09:00:00', 'feed'],
        ['Event B', 'EUR', 'Medium', '2021-06-01 10:00:00', 'feed'],
        ['Event A', 'USD', 'High', '2021-06-01 09:00:00', 'feed'], // Duplicate of Event A
        ['Event C', 'GBP', 'Low', '2021-06-01 11:00:00', 'feed'],
        ['Event B', 'EUR', 'Medium', '2021-06-01 10:00:00', 'feed'], // Duplicate of Event B
        ['Event A', 'USD', 'High', '2021-06-01 09:00:00', 'feed'], // Another duplicate of Event A
    ]);

    $importer = $this->getCalendarCsvImporter();
    $result = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    // Should successfully import without cardinality violations
    expect($result['files_total'])->toBe(1);
    expect($result['files_processed'])->toBe(1);
    expect($result['created'])->toBe(3); // 3 unique events from 6 rows (3 duplicates removed)
    expect($result['errors'])->toBe(0);  // No cardinality violations

    // Verify file was processed successfully
    $fileKey = array_key_first($result['files']);
    expect($result['files'][$fileKey]['status'])->toBe('imported');
    expect($result['files'][$fileKey]['errors'])->toHaveCount(0);

    // Should only have 3 unique calendar events (3 duplicates removed)
    expect(CalendarEvent::count())->toBe(3);

    // Verify the correct events were created
    $titles = CalendarEvent::orderBy('event_time_utc')->pluck('title')->toArray();
    expect($titles)->toBe(['Event A', 'Event B', 'Event C']);
});
