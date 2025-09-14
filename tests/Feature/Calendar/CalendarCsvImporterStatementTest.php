<?php

use App\Application\Calendar\CalendarCsvImporter;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('parses statement_sample.csv dates using provided tz and stores UTC, normalizes None->Low, min-impact skips Low, and re-import idempotent', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // Copy fixture into fake storage path used by importer
    $fixture = base_path('tests/fixtures/calendar/statement_sample.csv');
    $contents = file_get_contents($fixture);

    $disk->put('calendar_csv/statement_sample.csv', $contents);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);

    // First import with tz=Asia/Tokyo (local dates without offset) and minImpact Low
    // keepFiles=true so the file remains for a second import to validate idempotency
    // Under the new policy, CSV local datetimes are treated as UTC
    $res1 = $importer->importDirectory($path, false, true, 'Low');

    // Expect two parsed rows and two events created
    expect($res1['created'])->toBe(2);
    expect(CalendarEvent::count())->toBe(2);

    // Check normalization: the second row had 'None' -> should be stored as 'Low'
    $evtNone = CalendarEvent::where('title', 'like', '%Industrial Production%')->first();
    expect($evtNone)->not->toBeNull();
    expect($evtNone->impact)->toBe('Low');

    // Verify date for first row converted to UTC from Asia/Tokyo
    $evtFirst = CalendarEvent::where('title', 'like', '%Capital Spending YoY%')->first();
    expect($evtFirst)->not->toBeNull();

    // The fixture times are now treated as UTC directly
    $expected = CarbonImmutable::createFromFormat('Y, F d, H:i', '2021, September 01, 00:50', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($evtFirst->event_time_utc->format(DATE_ATOM))->toBe($expected);

    // Now import again - should be idempotent (updates, not creates)
    // The importer moves processed files to processed/, so copy it back so the second import can read it again
    if (Storage::disk('local')->exists('calendar_csv/processed/statement_sample.csv')) {
        Storage::disk('local')->copy('calendar_csv/processed/statement_sample.csv', 'calendar_csv/statement_sample.csv');
    }
    $res2 = $importer->importDirectory($path, false, true, 'Low');
    expect($res2['created'])->toBe(0);
    expect($res2['updated'])->toBeGreaterThanOrEqual(1);

    // Now import with min-impact=Medium -> Low events should be skipped
    CalendarEvent::query()->delete();
    $res3 = $importer->importDirectory($path, false, true, 'Medium');

    // Only the Medium/High events should be created; our fixture has two Low events -> 0 created
    expect($res3['created'])->toBe(0);
    expect(CalendarEvent::count())->toBe(0);
});
