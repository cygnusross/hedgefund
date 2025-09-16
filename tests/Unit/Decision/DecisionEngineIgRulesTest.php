<?php

declare(strict_types=1);

use App\Domain\Decision\DecisionEngine;
use App\Domain\Rules\AlphaRules;

it('enforces ig minNormalStopOrLimitDistance by pushing sl/tp outward', function () {
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
      sl_atr_mult: 1.0
      tp_atr_mult: 2.0  # Higher TP for better RR
      spread_ceiling_pips: 10.0
      rr: 1.0  # Accept 1:1 RR for this test
    cooldowns: {}
    overrides: {}
    YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $yaml);

    $rules = new AlphaRules($path);
    $rules->reload();

    // Pair EURUSD, pip/tick = 0.0001
    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 5, // Larger ATR for better SL/TP distances
            'spread_estimate_pips' => 0.5,
            'sentiment' => ['long_pct' => 60.0, 'short_pct' => 40.0],
            'ig_rules' => ['minNormalStopOrLimitDistance' => 20], // 20 points = 20 * 0.0001 = 0.0020
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    expect($res['action'])->toBe('buy');
    // entry - sl should be at least 0.0020
    $delta = $res['entry'] - $res['sl'];
    expect($delta)->toBeGreaterThanOrEqual(0.0019); // allow tiny rounding
    expect($delta)->toBeLessThanOrEqual(0.0021);
});
