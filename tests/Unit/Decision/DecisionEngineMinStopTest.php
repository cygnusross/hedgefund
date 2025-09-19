<?php

declare(strict_types=1);

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Rules\AlphaRules;

it('enforces minimum stop loss for margin safety', function () {
    $rulesYaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk:
    per_trade_pct:
        default: 1.0
execution:
    sl_atr_mult: 2.0
    tp_atr_mult: 4.0
    sl_min_pips: 15.0  # Minimum 15 pips for margin safety
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Test case 1: Low ATR that would normally result in < 15 pip stop
    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 5.0, // Low ATR: 5 pips * 2.0 mult = 10 pips (< 15 minimum)
            'spread_estimate_pips' => 0.5,
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('buy');

    // Calculate expected stop distance
    $entry = $res['entry'];
    $sl = $res['sl'];
    $actualPips = abs($entry - $sl) / 0.0001; // Convert to pips for EURUSD

    // Should be minimum 15 pips, not the calculated 10 pips (5 ATR * 2.0 mult)
    expect($actualPips)->toBeGreaterThanOrEqual(14.9); // Allow tiny rounding
    expect($actualPips)->toBeLessThanOrEqual(15.1);
});

it('allows ATR-based stop when it exceeds minimum', function () {
    $rulesYaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk:
    per_trade_pct:
        default: 1.0
execution:
    sl_atr_mult: 2.0
    tp_atr_mult: 4.0
    sl_min_pips: 15.0
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Test case 2: High ATR that naturally exceeds minimum
    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 15.0, // High ATR: 15 pips * 2.0 mult = 30 pips (> 15 minimum)
            'spread_estimate_pips' => 0.5,
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('buy');

    // Calculate actual stop distance
    $entry = $res['entry'];
    $sl = $res['sl'];
    $actualPips = abs($entry - $sl) / 0.0001; // Convert to pips for EURUSD

    // Should be ATR-based: 30 pips (15 ATR * 2.0 mult), not the minimum 15
    expect($actualPips)->toBeGreaterThanOrEqual(29.9); // Allow tiny rounding
    expect($actualPips)->toBeLessThanOrEqual(30.1);
});

it('works with different minimum stop loss values', function () {
    $rulesYaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk:
    per_trade_pct:
        default: 1.0
execution:
    sl_atr_mult: 2.0
    tp_atr_mult: 4.0
    sl_min_pips: 20.0  # Higher minimum for conservative trading
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 8.0, // 8 * 2.0 = 16 pips (< 20 minimum)
            'spread_estimate_pips' => 0.5,
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('buy');

    $entry = $res['entry'];
    $sl = $res['sl'];
    $actualPips = abs($entry - $sl) / 0.0001;

    // Should enforce 20 pip minimum, not the 16 pip ATR calculation
    expect($actualPips)->toBeGreaterThanOrEqual(19.9);
    expect($actualPips)->toBeLessThanOrEqual(20.1);
});
