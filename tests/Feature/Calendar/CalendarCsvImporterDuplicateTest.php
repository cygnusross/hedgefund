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
