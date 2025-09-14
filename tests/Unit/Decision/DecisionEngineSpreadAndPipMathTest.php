<?php

declare(strict_types=1);

use App\Domain\Decision\DecisionEngine;
use App\Domain\Rules\AlphaRules;

it('holds when spread exceeds configured ceiling', function () {
    $yaml = <<<'YAML'
    gates:
      news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.5
    confluence:
      require_trend_alignment_for_moderate: true
      allow_strong_against_trend: false
    risk:
      per_trade_pct:
        default: 1.0
    execution:
      sl_atr_mult: 2.0
      tp_atr_mult: 4.0
      spread_ceiling_pips: 0.4
    cooldowns: {}
    overrides: {}
    YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $yaml);

    $rules = new AlphaRules($path);
    $rules->reload();

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 10,
            'spread_estimate_pips' => 0.5, // above ceiling
            'sentiment' => ['long_pct' => 50.0, 'short_pct' => 50.0],
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.35, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('spread_too_wide');
});

it('computes sl/tp deltas correctly for JPY vs non-JPY pairs', function () {
    $yaml = <<<'YAML'
    gates:
      news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
    confluence:
      require_trend_alignment_for_moderate: true
      allow_strong_against_trend: false
    risk:
      per_trade_pct:
        default: 1.0
    execution:
      sl_atr_mult: 2.0
      tp_atr_mult: 4.0
      spread_ceiling_pips: 5.0
    cooldowns: {}
    overrides: {}
    YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $yaml);

    $rules = new AlphaRules($path);
    $rules->reload();

    // EURUSD (pipSize=0.0001)
    $ctx1 = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 10,
            'spread_estimate_pips' => 0.5,
            'sentiment' => ['long_pct' => 60.0, 'short_pct' => 40.0],
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res1 = $engine->decide($ctx1, $rules);

    // ATR=10 pips, sl_mult=2 => slPips=20 => delta = 20 * 0.0001 = 0.0020
    expect($res1['action'])->toBe('buy');
    // Derive expected label from thresholds to avoid hard-coded mismatch
    $modThreshold = $rules->getGate('news_threshold.moderate');
    $strongThreshold = $rules->getGate('news_threshold.strong');
    $strength = $ctx1['news']['strength'];
    $expectedLabel = 'weak';
    if ($strength >= $strongThreshold) {
        $expectedLabel = 'strong';
    } elseif ($strength >= $modThreshold) {
        $expectedLabel = 'moderate';
    }

    expect($res1['news_label'])->toBe($expectedLabel);
    $diff1 = abs($res1['entry'] - $res1['sl']);
    expect(abs($diff1 - 0.0020))->toBeLessThan(0.00001);

    // USDJPY (pipSize=0.01)
    $ctx2 = $ctx1;
    $ctx2['meta']['pair_norm'] = 'USDJPY';
    $ctx2['market']['last_price'] = 155.25;

    $res2 = $engine->decide($ctx2, $rules);

    // ATR=10 pips, sl_mult=2 => slPips=20 => delta = 20 * 0.01 = 0.20
    expect($res2['action'])->toBe('buy');
    $diff2 = abs($res2['entry'] - $res2['sl']);
    expect(abs($diff2 - 0.20))->toBeLessThan(0.0001);
});
