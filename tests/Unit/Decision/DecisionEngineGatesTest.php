<?php

declare(strict_types=1);

use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Rules\AlphaRules;

it('holds when ADX below configured minimum', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['adx_min' => 20]]);

    // Use a plain features array for tests; DecisionEngine will read numeric keys used below
    $featuresArr = ['ema20' => 1.0, 'atr5m' => 0.1, 'ema20_z' => 0.0, 'adx5m' => 0.0, 'trend30m' => 'flat'];

    $payload = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 1],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'spread_estimate_pips' => 0.5, 'atr5m_pips' => 10],
        'features' => $featuresArr,
        'calendar' => ['within_blackout' => false],
    ];

    // inject a low adx into the payload
    $arr = $payload;
    $arr['features']['adx5m'] = 10;

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($arr))->toArray();

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('low_adx');
});

it('holds when ema z stretched beyond configured max', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['z_abs_max' => 1.0]]);

    $featuresArr = ['ema20' => 1.0, 'atr5m' => 0.1, 'ema20_z' => 0.0, 'adx5m' => 0.0, 'trend30m' => 'flat'];

    $payload = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 1],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'spread_estimate_pips' => 0.5, 'atr5m_pips' => 10],
        'features' => $featuresArr,
        'calendar' => ['within_blackout' => false],
    ];

    $arr = $payload;
    $arr['features']['ema20_z'] = 1.5;

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($arr))->toArray();

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('stretched_z');
});

it('allows lower adx when market override present', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, [
        'gates' => [
            'adx_min' => 25,
            'atr_min_pips' => 2.5,
        ],
        'confluence' => ['require_trend_alignment_for_moderate' => true, 'allow_strong_against_trend' => false],
        'risk' => [
            'per_trade_pct' => ['default' => 1.0, 'strong' => 1.0, 'moderate' => 1.0],
            'per_trade_cap_pct' => 2.0,
            'pair_exposure_pct' => 100,
        ],
        'execution' => [
            'sl_atr_mult' => 1.8,
            'tp_atr_mult' => 3.6,
            'sl_min_pips' => 5.0,
            'spread_ceiling_pips' => 5.0,
        ],
        'cooldowns' => [],
        'overrides' => [],
    ]);

    $payload = [
        'meta' => ['pair_norm' => 'NZDUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 0.60,
            'spread_estimate_pips' => 0.8,
            'atr5m_pips' => 5.0,
            'gate_overrides' => ['adx_min' => 15],
        ],
        'features' => [
            'ema20' => 0.60,
            'atr5m' => 0.0005,
            'ema20_z' => 0.2,
            'recentRangePips' => 12,
            'adx5m' => 18,
            'trend30m' => 'up',
            'supportLevels' => [],
            'resistanceLevels' => [],
            'rsi14' => 50,
            'stoch_k' => 50,
            'stoch_d' => 50,
            'williamsR' => -50,
            'cci' => 0,
        ],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($payload))->toArray();

    expect($res['action'])->toBe('buy');
    expect($res['reasons'])->not->toContain('low_adx');
});

it('blocks when atr is below configured minimum', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, [
        'gates' => [
            'adx_min' => 25,
            'atr_min_pips' => 2.5,
        ],
        'confluence' => ['require_trend_alignment_for_moderate' => true, 'allow_strong_against_trend' => false],
        'risk' => [
            'per_trade_pct' => ['default' => 1.0, 'strong' => 1.0, 'moderate' => 1.0],
            'per_trade_cap_pct' => 2.0,
            'pair_exposure_pct' => 100,
        ],
        'execution' => [
            'sl_atr_mult' => 1.8,
            'tp_atr_mult' => 3.6,
            'sl_min_pips' => 5.0,
            'spread_ceiling_pips' => 5.0,
        ],
        'cooldowns' => [],
        'overrides' => [],
    ]);

    $payload = [
        'meta' => ['pair_norm' => 'NZDUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 0.60,
            'spread_estimate_pips' => 0.8,
            'atr5m_pips' => 1.2,
        ],
        'features' => [
            'ema20' => 0.60,
            'atr5m' => 0.00012,
            'ema20_z' => 0.1,
            'recentRangePips' => 5,
            'adx5m' => 30,
            'trend30m' => 'up',
            'supportLevels' => [],
            'resistanceLevels' => [],
            'rsi14' => 50,
            'stoch_k' => 50,
            'stoch_d' => 50,
            'williamsR' => -50,
            'cci' => 0,
        ],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($payload))->toArray();

    expect($res['action'])->toBe('hold');
    expect($res['reasons'])->toContain('atr_too_low');
});
