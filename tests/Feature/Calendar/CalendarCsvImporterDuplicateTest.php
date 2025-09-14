<?php

use App\Application\Calendar\CalendarCsvImporter;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('treats duplicate rows across files as update', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv1 = implode("\n", [
        'title,currency,impact,date,source',
        'DupeEvent,USD,Medium,2025-10-01 09:00:00,feed1',
    ]);

    $csv2 = implode("\n", [
        'title,currency,impact,date,source',
        'DupeEvent,USD,High,2025-10-01 09:00:00,feed2',
    ]);

    $disk->put('calendar_csv/file1.csv', $csv1);
    $disk->put('calendar_csv/file2.csv', $csv2);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);
    // Import first file (both exist on disk; processed/ handling will move processed files)
    $res1 = $importer->importDirectory($path, false, false, 'Low');

    // Remove first file so second import only processes file2
    $disk->delete('calendar_csv/file1.csv');

    $res2 = $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(1);

    $ev = CalendarEvent::first();
    expect($ev->impact)->toBe('High');
    expect($ev->source)->toBe('feed2');
});
