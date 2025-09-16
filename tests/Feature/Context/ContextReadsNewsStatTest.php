<?php

use App\Application\ContextBuilder;
use App\Models\NewsStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

use Illuminate\Support\Facades\Artisan;

it('context builder reads todays news stat from DB', function () {
    Artisan::call('migrate');
    $pair = 'EUR/USD';
    $pair_norm = strtoupper(str_replace('/', '-', $pair));

    // Seed a NewsStat for today
    $today = Carbon::now('UTC')->format('Y-m-d');
    NewsStat::upsertFromApi([
        'pair_norm' => $pair_norm,
        'stat_date' => $today,
        'pos' => 5,
        'neg' => 1,
        'neu' => 0,
        'raw_score' => 0.4,
        'strength' => 0.5,
        'source' => 'forexnewsapi',
        'fetched_at' => Carbon::now('UTC'),
        'payload' => ['seed' => true],
    ]);

    // Build a minimal context: construct required dependencies via container resolution
    $updater = app()->make(App\Application\Candles\CandleUpdaterContract::class);
    $calendar = app()->make(App\Application\Calendar\CalendarLookup::class);

    $cb = new ContextBuilder($updater, $calendar);
    $ctx = $cb->build($pair, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    expect($ctx)->toBeArray();
    expect($ctx['news']['direction'])->toBe('buy');
    expect($ctx['news']['strength'])->toBe((float) 0.5);
    expect($ctx['news']['counts']['pos'])->toBe(5);
    expect($ctx['news']['counts']['neg'])->toBe(1);
});
