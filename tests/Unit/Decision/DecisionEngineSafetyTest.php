<?php

use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\DTO\DecisionMetadata;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Market\FeatureSet;
use App\Domain\Rules\AlphaRules;

function makeContext(array $overrides = []): array
{
    $ts = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $features = new FeatureSet(
        $ts,
        1.0,
        0.1,
        0.0,
        10.0,
        25.0,
        'flat',
        [],
        [],
    );

    $meta = [];
    $meta['data_age_sec'] = array_key_exists('data_age_sec', $overrides) ? $overrides['data_age_sec'] : 0;
    $ctx = new DecisionContext('EUR/USD', $ts, $features, new DecisionMetadata($meta));
    $arr = $ctx->toArray();

    $spread = array_key_exists('spread', $overrides) ? $overrides['spread'] : 1.0;
    $arr['market'] = array_merge([
        'status' => $overrides['status'] ?? 'TRADEABLE',
        'spread_estimate_pips' => $spread,
        'last_price' => 1.1,
        'atr5m_pips' => 10,
    ], $overrides['market'] ?? []);
    if (array_key_exists('blackout', $overrides)) {
        $arr['blackout'] = (bool) $overrides['blackout'];
    }

    $arr['calendar'] = ['within_blackout' => $overrides['within_blackout'] ?? false];
    $arr['meta'] = array_merge($arr['meta'], ['sleeve_balance' => 10000.0]);

    return $arr;
}

function makeRules(array $overrides = []): AlphaRules
{
    $path = __DIR__.'/fixtures/empty_rules.yaml';
    $rules = new AlphaRules($path);

    // Avoid calling reload in tests; use getGate with defaults or simulate by setting internal data.
    return $rules;
}

it('blocks when market status not allowed', function () {
    $ctx = makeContext(['status' => 'CLOSED']);
    $rules = makeRules();
    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('status_closed');
});

it('allows when market status allowed', function () {
    $ctx = makeContext(['status' => 'TRADEABLE']);
    $rules = makeRules();
    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('ok');
});

it('blocks when spread required but missing', function () {
    $ctx = makeContext(['spread' => null]);
    $rules = makeRules();
    // Use a real AlphaRules instance and inject the gate via reflection to avoid
    // extending the final AlphaRules class.
    $rulesWith = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rulesWith);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rulesWith, ['gates' => ['spread_required' => true]]);

    $engine = new LiveDecisionEngine($rulesWith);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('no_spread');
});

it('blocks when data is stale', function () {
    $ctx = makeContext(['data_age_sec' => 9999]);
    $rules = makeRules();
    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('bar_data_stale');
});

it('blocks when no bar data is available', function () {
    $ctx = makeContext(['data_age_sec' => null]);
    $rules = makeRules();
    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('no_bar_data');
});

it('blocks on calendar blackout', function () {
    $ctx = makeContext(['within_blackout' => true]);
    $rules = makeRules();
    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('blackout');
});

it('blocks when ADX below configured minimum', function () {
    $ctx = makeContext();
    $payload = $ctx;
    $payload['features']['adx5m'] = 10; // below default 20

    $ctxLowAdx = DecisionRequest::fromArray($payload);

    $rulesWith = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rulesWith);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    // Ensure adx_min exists (default 20) - we'll rely on default, but explicitly set gates to avoid surprises
    $prop->setValue($rulesWith, ['gates' => ['adx_min' => 20]]);

    $engine = new LiveDecisionEngine($rulesWith);
    $res = $engine->decide($ctxLowAdx)->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('low_adx');
});

it('blocks when EMA Z is stretched beyond configured max', function () {
    $ctx = makeContext();
    $payload = $ctx;
    $payload['features']['ema20_z'] = 1.5; // above default 1.0

    $ctxStretched = DecisionRequest::fromArray($payload);

    $rulesWith = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rulesWith);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rulesWith, ['gates' => ['z_abs_max' => 1.0]]);

    $engine = new LiveDecisionEngine($rulesWith);
    $res = $engine->decide($ctxStretched)->toArray();
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('stretched_z');
});
