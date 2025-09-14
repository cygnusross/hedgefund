<?php

use App\Models\NewsStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('upserts a news stat and updates the same pair/date', function () {
    Artisan::call('migrate');
    // ensure the migration created the table
    if (! \Illuminate\Support\Facades\Schema::hasTable('news_stats')) {
        throw new \RuntimeException('news_stats table not present after migrate');
    }
    $pair = 'EUR-USD';
    $date = '2025-09-12';

    $statA = NewsStat::upsertFromApi([
        'pair_norm' => $pair,
        'stat_date' => $date,
        'pos' => 1,
        'neg' => 0,
        'neu' => 0,
        'raw_score' => 0.1,
        'strength' => 0.2,
        'source' => 'forexnewsapi',
        'fetched_at' => Carbon::now('UTC'),
        'payload' => ['a' => 1],
    ]);

    $exists = NewsStat::where('pair_norm', $pair)->whereDate('stat_date', $date)->exists();
    if (! $exists) {
        $all = NewsStat::all()->toArray();
        throw new RuntimeException('Expected row not found; rows: '.json_encode($all));
    }
    expect($exists)->toBeTrue();

    // Update same pair/date
    $statB = NewsStat::upsertFromApi([
        'pair_norm' => $pair,
        'stat_date' => $date,
        'pos' => 2,
        'neg' => 1,
        'neu' => 0,
        'raw_score' => 0.5,
        'strength' => 0.6,
        'source' => 'forexnewsapi',
        'fetched_at' => Carbon::now('UTC'),
        'payload' => ['b' => 2],
    ]);

    $fresh = NewsStat::where('pair_norm', $pair)->whereDate('stat_date', $date)->first();
    expect($fresh->pos)->toBe(2);
    expect($fresh->neg)->toBe(1);
    expect((float) $fresh->raw_score)->toBe(0.5);
});
