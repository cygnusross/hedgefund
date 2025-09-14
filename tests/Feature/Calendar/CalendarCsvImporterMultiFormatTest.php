<?php

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\CalendarCsvTestHelpers;

uses(RefreshDatabase::class, CalendarCsvTestHelpers::class);

it('handles original format with Date,Event,Impact,Currency headers', function () {
    $this->setupCalendarCsvTest();

    $csvContent = "Date,Event,Impact,Currency\n";
    $csvContent .= "\"2025, August 01, 12:00\",\"Fed's Bowman speech\",Medium,USD\n";
    $csvContent .= "\"2025, August 01, 14:00\",\"ISM Manufacturing PMI\",High,USD\n";

    Storage::disk('local')->put('calendar_csv/original_format.csv', $csvContent);

    $importer = $this->getCalendarCsvImporter();
    $result = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect($result['files_processed'])->toBe(1);
    expect($result['created'])->toBe(2);
    expect($result['errors'])->toBe(0);
    expect(CalendarEvent::count())->toBe(2);

    $events = CalendarEvent::orderBy('event_time_utc')->get();
    expect($events[0]->title)->toBe("Fed's Bowman speech");
    expect($events[1]->title)->toBe('ISM Manufacturing PMI');
});

it('handles new format with Start,Name,Impact,Currency headers', function () {
    $this->setupCalendarCsvTest();

    $csvContent = "Id,Start,Name,Impact,Currency\n";
    $csvContent .= "f7cf329c-4739-4ab7-9abf-b478e89da91c,08/01/2025 12:00:00,Fed's Bowman speech,MEDIUM,USD\n";
    $csvContent .= "8927f9bb-68b5-4353-a30a-e0a06fab90a2,08/01/2025 14:00:00,ISM Manufacturing PMI,HIGH,USD\n";

    Storage::disk('local')->put('calendar_csv/new_format.csv', $csvContent);

    $importer = $this->getCalendarCsvImporter();
    $result = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect($result['files_processed'])->toBe(1);
    expect($result['created'])->toBe(2);
    expect($result['errors'])->toBe(0);
    expect(CalendarEvent::count())->toBe(2);

    $events = CalendarEvent::orderBy('event_time_utc')->get();
    expect($events[0]->title)->toBe("Fed's Bowman speech");
    expect($events[0]->impact)->toBe('Medium'); // normalized from MEDIUM
    expect($events[1]->title)->toBe('ISM Manufacturing PMI');
    expect($events[1]->impact)->toBe('High'); // normalized from HIGH
});

it('ignores unknown columns like Id', function () {
    $this->setupCalendarCsvTest();

    $csvContent = "Id,Start,Name,Impact,Currency,ExtraColumn\n";
    $csvContent .= "uuid-here,08/01/2025 12:00:00,Test Event,HIGH,USD,ignored\n";

    Storage::disk('local')->put('calendar_csv/with_extra.csv', $csvContent);

    $importer = $this->getCalendarCsvImporter();
    $result = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect($result['files_processed'])->toBe(1);
    expect($result['created'])->toBe(1);
    expect($result['errors'])->toBe(0);
    expect(CalendarEvent::count())->toBe(1);

    $event = CalendarEvent::first();
    expect($event->title)->toBe('Test Event');
    expect($event->currency)->toBe('USD');
});
