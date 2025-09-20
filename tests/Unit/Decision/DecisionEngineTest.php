<?php

use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\DTO\DecisionMetadata;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Market\FeatureSet;
use App\Domain\Rules\AlphaRules;

it('returns hold by default', function () {
    // Use a recent timestamp
    $ts = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    // Minimal FeatureSet with required constructor args
    $features = new FeatureSet(
        $ts,
        1.0, // ema20
        0.1, // atr5m
        0.0, // ema20_z
        10.0, // recentRangePips
        5.0, // adx5m
        'flat', // trend30m
        [], // supportLevels
        [], // resistanceLevels
    );
    // Use a recent timestamp
    $ts = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $ctx = new DecisionContext('EUR/USD', $ts, $features, new DecisionMetadata);

    // AlphaRules requires a path; provide a dummy file and avoid reload
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromContext($ctx))->toArray();

    expect($res)->toBeArray();
    expect($res['action'])->toBe('hold');
    expect($res['confidence'])->toBe(0.0);
    // With safety gates in place, an empty context will lack market status and be held
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('status_closed');
});
