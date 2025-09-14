<?php

use App\Application\Calendar\CalendarCsvImporter;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('rejects invalid currency tokens', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'BadCurrencyEvent,USDX,Medium,2025-10-01 09:00:00,feed',
    ]);

    $disk->put('calendar_csv/badcurrency.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);
    $res = $importer->importDirectory($path, false, false, 'Low');

    // no events created
    expect(CalendarEvent::count())->toBe(0);

    // file result should include an invalid currency error
    $filePath = $disk->path('calendar_csv/badcurrency.csv');
    $fileRes = $res['files'][$filePath];
    expect(collect($fileRes['errors'])->pluck('error')->first())->toContain('invalid currency');
});

it('rejects invalid impact values', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'BadImpactEvent,USD,BadValue,2025-10-01 09:00:00,feed',
    ]);

    $disk->put('calendar_csv/badimpact.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);
    $res = $importer->importDirectory($path, false, false, 'Low');

    expect(CalendarEvent::count())->toBe(0);

    $filePath = $disk->path('calendar_csv/badimpact.csv');
    $fileRes = $res['files'][$filePath];
    expect(collect($fileRes['errors'])->pluck('error')->first())->toContain('invalid impact');
});
