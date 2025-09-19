<?php

declare(strict_types=1);

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Rules\AlphaRules;

it('holds when contrarian sentiment is against proposed action', function () {
    // Create a minimal rules YAML on disk and load it via AlphaRules
    $yaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
    sentiment:
        mode: contrarian
        contrarian_threshold_pct: 60.0
        neutral_band_low_pct: 45.0
        neutral_band_high_pct: 55.0
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

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 10,
            'spread_estimate_pips' => 0.5,
            'sentiment' => ['long_pct' => 70.0, 'short_pct' => 30.0],
        ],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'], // moderate
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('contrarian_crowd_long');
});
