<?php

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\CalendarCsvTestHelpers;

uses(RefreshDatabase::class, CalendarCsvTestHelpers::class);

it('rejects invalid currency tokens', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('badcurrency.csv', [
        ['BadCurrencyEvent', 'USDX', 'Medium', '2025-10-01 09:00:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $res = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    // no events created
    expect(CalendarEvent::count())->toBe(0);

    // file result should include an invalid currency error
    $filePath = Storage::disk('local')->path('calendar_csv/badcurrency.csv');
    $fileRes = $res['files'][$filePath];
    expect(collect($fileRes['errors'])->pluck('error')->first())->toContain('invalid currency');
});

it('rejects invalid impact values', function () {
    $this->setupCalendarCsvTest();

    $this->createSimpleCalendarCsv('badimpact.csv', [
        ['BadImpactEvent', 'USD', 'BadValue', '2025-10-01 09:00:00', 'feed'],
    ]);

    $importer = $this->getCalendarCsvImporter();
    $res = $importer->importDirectory($this->getCalendarCsvPath(), false, false, 'Low');

    expect(CalendarEvent::count())->toBe(0);

    $filePath = Storage::disk('local')->path('calendar_csv/badimpact.csv');
    $fileRes = $res['files'][$filePath];
    expect(collect($fileRes['errors'])->pluck('error')->first())->toContain('invalid impact');
});
