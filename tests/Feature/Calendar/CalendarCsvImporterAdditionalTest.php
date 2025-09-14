<?php

use App\Application\Calendar\CalendarCsvImporter;
use App\Models\CalendarEvent;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('parses Date/Event/Impact/Currency headers and uses --tz for non-offset dates', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'Date,Event,Impact,Currency,Source',
        '2025-09-07 05:15,Test Event,High,EUR,feed',
    ]);

    // The header shape intentionally uses our alternate format; note the date value does not contain offset
    $disk->put('calendar_csv/headershape.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);
    // New policy: CSV local datetimes are treated as UTC
    $res = $importer->importDirectory($path, false, false, 'Medium');

    expect(CalendarEvent::count())->toBe(1);

    $ev = CalendarEvent::first();
    expect($ev->title)->toBe('Test Event');

    // Date value is treated as UTC
    $expected = CarbonImmutable::createFromFormat('Y, F d, H:i', '2025, September 07, 05:15', new \DateTimeZone('UTC'))
        ->setTimezone('UTC')
        ->format(DATE_ATOM);

    expect($ev->event_time_utc->format(DATE_ATOM))->toBe($expected);
});

it('--min-impact filters out lower impact rows', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'LowEvent,USD,Low,2025-10-01 09:00:00,feed',
        'MedEvent,USD,Medium,2025-10-01 10:00:00,feed',
    ]);

    $disk->put('calendar_csv/minimpact.csv', $csv);
    $path = $disk->path('calendar_csv');

    // Set min-impact to Medium -> LowEvent should be skipped
    $importer = app(CalendarCsvImporter::class);
    $res = $importer->importDirectory($path, false, false, 'Medium');

    $titles = CalendarEvent::pluck('title')->all();
    expect(in_array('LowEvent', $titles))->toBeFalse();
    expect(in_array('MedEvent', $titles))->toBeTrue();
});

it('expands currency ALL into unique market currencies', function () {
    Artisan::call('migrate');
    CalendarEvent::query()->delete();
    Market::query()->delete();

    Market::create(['name' => 'EURUSD', 'symbol' => 'EUR/USD', 'epic' => 'EURUSD', 'currencies' => ['EUR', 'USD'], 'is_active' => 1]);
    Market::create(['name' => 'GBPUSD', 'symbol' => 'GBP/USD', 'epic' => 'GBPUSD', 'currencies' => ['GBP', 'USD'], 'is_active' => 1]);

    Storage::fake('local');
    $disk = Storage::disk('local');

    $csv = implode("\n", [
        'title,currency,impact,date,source',
        'AllEvent,ALL,Medium,2025-09-07T05:15:00-04:00,feed',
    ]);

    $disk->put('calendar_csv/all.csv', $csv);
    $path = $disk->path('calendar_csv');

    $importer = app(CalendarCsvImporter::class);
    $res = $importer->importDirectory($path, false, false, 'Medium');

    expect(CalendarEvent::count())->toBe(3);

    $currencies = CalendarEvent::pluck('currency')->sort()->values()->all();
    expect($currencies)->toContain('EUR');
    expect($currencies)->toContain('USD');
    expect($currencies)->toContain('GBP');
});
