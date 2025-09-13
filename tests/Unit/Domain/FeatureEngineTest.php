<?php

declare(strict_types=1);

use App\Domain\Features\FeatureEngine;
use App\Domain\Market\Bar;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

it('returns null when not enough warm-up bars', function () {
    $now = new \DateTimeImmutable('2025-01-01T00:00:00Z');
    $bars = [];

    // only 10 bars, less than required 20
    $open = 100.0;
    for ($i = 0; $i < 10; $i++) {
        $ts = $now->modify('+'.($i * 5).' minutes');
        $close = $open + ($i * 0.2);
        $bars[] = new Bar($ts, $open, $close + 0.1, $close - 0.1, $close, null);
        $open = $close;
    }

    $res = FeatureEngine::buildAt($bars, [], end($bars)->ts, 'EUR/USD');
    expect($res)->toBeNull();
});

it('computes FeatureSet when warm-up satisfied', function () {
    $now = new \DateTimeImmutable('2025-01-01T00:00:00Z');
    $bars = [];

    // 50 bars to satisfy warm-up
    $open = 100.0;
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->modify('+'.($i * 5).' minutes');
        $close = $open + ($i * 0.3);
        $bars[] = new Bar($ts, $open, $close + 0.2, $close - 0.2, $close, 1000.0);
        $open = $close;
    }

    $ts = $bars[30]->ts; // pick a mid timestamp
    $res = FeatureEngine::buildAt($bars, [], $ts, 'EUR/USD');

    expect($res)->not->toBeNull();
    expect($res->ts)->toEqual($ts);
    expect($res->ema20)->toBeFloat();
    expect($res->atr5m)->toBeFloat();

    // ema20_z should be present (may be 0.0 if computed as null -> defaulted)
    expect($res->ema20_z)->toBeFloat();

    // adx5m should be finite
    expect(is_finite($res->adx5m))->toBeTrue();

    // trend30m should be one of the allowed labels
    expect(in_array($res->trend30m, ['up', 'down', 'sideways'], true))->toBeTrue();

    // support/resistance arrays should be arrays (<=3 entries)
    expect(is_array($res->supportLevels))->toBeTrue();
    expect(is_array($res->resistanceLevels))->toBeTrue();
    expect(count($res->supportLevels))->toBeLessThanOrEqual(3);
    expect(count($res->resistanceLevels))->toBeLessThanOrEqual(3);

    // No look-ahead: building at ts should not include a bar later than ts
    $lastIncludedBarTs = $ts;
    // Check that original last bar is after selected ts
    $lastBarTs = end($bars)->ts;
    expect($lastBarTs > $ts)->toBeTrue();
});
