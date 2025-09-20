<?php

declare(strict_types=1);

use App\Domain\FX\DatabaseSpreadEstimator;
use App\Models\Spread;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns latest recorded spread from database', function () {
    $estimator = new DatabaseSpreadEstimator;

    Spread::create([
        'pair' => 'NZD/USD',
        'spread_pips' => 1.8,
        'recorded_at' => now('UTC')->subMinutes(5),
    ]);

    Spread::create([
        'pair' => 'NZD/USD',
        'spread_pips' => 2.2,
        'recorded_at' => now('UTC'),
    ]);

    $spread = $estimator->estimatePipsForPair('NZD/USD');

    expect($spread)->toBe(2.2);
    expect($estimator->getMarketStatusForPair('NZD/USD'))->toBe('TRADEABLE');
});

it('returns null when no spread data available', function () {
    $estimator = new DatabaseSpreadEstimator;

    expect($estimator->estimatePipsForPair('EUR/USD'))->toBeNull();
    expect($estimator->getMarketStatusForPair('EUR/USD'))->toBeNull();
});
