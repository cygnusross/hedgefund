<?php

use App\Domain\Decision\DecisionEngine;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;

it('computes size for eurusd', function () {
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
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Neutral ledger
    $fake = new class implements PositionLedgerContract
    {
        public function todaysPnLPct(): float
        {
            return 0.0;
        }

        public function lastTrade(): ?array
        {
            return null;
        }

        public function openPositionsCount(): int
        {
            return 0;
        }

        public function pairExposurePct(string $pair): float
        {
            return 0.0;
        }
    };

    app()->instance(PositionLedgerContract::class, $fake);

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'ig_rules' => ['pip_value' => 1.0, 'size_step' => 0.01]],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    // Risk: 1% of 10000 = 100; slPips = sl_atr_mult * atr = 2 * 10 = 20; pip_value=1 => rawSize=100/(20*1)=5
    expect($res['size'])->toBe(5.0);

    // Restore default ledger binding
    app()->bind(\App\Domain\Execution\PositionLedgerContract::class, function () {
        return new \App\Domain\Execution\NullPositionLedger;
    });
});

it('computes size for usdjpy with different pip sizing', function () {
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
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    $fake = new class implements PositionLedgerContract
    {
        public function todaysPnLPct(): float
        {
            return 0.0;
        }

        public function lastTrade(): ?array
        {
            return null;
        }

        public function openPositionsCount(): int
        {
            return 0;
        }

        public function pairExposurePct(string $pair): float
        {
            return 0.0;
        }
    };

    app()->instance(PositionLedgerContract::class, $fake);

    // For USD/JPY, pip_value may be different — set pip_value to 0.9 to simulate
    $ctx = [
        'meta' => ['pair_norm' => 'USDJPY', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 154.2, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'ig_rules' => ['pip_value' => 0.9, 'size_step' => 0.01]],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    // RiskAmount = 100; slPips = 20; pip_value = 0.9 -> rawSize = 100/(20*0.9) = 5.555... -> floor to 5.55 step 0.01
    expect($res['size'])->toBe(5.55);

    // Restore default ledger binding
    app()->bind(\App\Domain\Execution\PositionLedgerContract::class, function () {
        return new \App\Domain\Execution\NullPositionLedger;
    });
});
