<?php

declare(strict_types=1);

use App\Models\ClientSentiment;
use App\Services\Sentiment\DatabaseSentimentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns sentiment from database by market id', function () {
    $provider = new DatabaseSentimentProvider;

    ClientSentiment::create([
        'market_id' => 'CS.D.NZDUSD.TODAY.IP',
        'pair' => 'NZD/USD',
        'long_pct' => 58.0,
        'short_pct' => 42.0,
        'recorded_at' => now('UTC'),
    ]);

    $result = $provider->fetch('CS.D.NZDUSD.TODAY.IP');

    expect($result)->toBeArray();
    expect($result['long_pct'])->toBe(58.0);
    expect($result['short_pct'])->toBe(42.0);
    expect($result['as_of'])->toBeString();
});

it('returns null when no sentiment available', function () {
    $provider = new DatabaseSentimentProvider;

    expect($provider->fetch('UNKNOWN'))->toBeNull();
});
