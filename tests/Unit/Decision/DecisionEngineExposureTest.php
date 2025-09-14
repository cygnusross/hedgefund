<?php

use App\Domain\Decision\DecisionEngine;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;

it('holds when open positions >= max_concurrent_positions', function () {
    $rulesYaml = <<<'YAML'
gates:
    max_concurrent_positions: 2
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk: {}
execution: {}
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Fake ledger that reports 3 open positions
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
            return 3;
        }

        public function pairExposurePct(string $pair): float
        {
            return 0.0;
        }
    };

    app()->instance(PositionLedgerContract::class, $fake);

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    expect($res['action'])->toBe('hold');
    expect($res['reasons'])->toContain('max_concurrent');

    // Restore default ledger binding
    app()->bind(\App\Domain\Execution\PositionLedgerContract::class, function () {
        return new \App\Domain\Execution\NullPositionLedger;
    });
});

it('holds when pair exposure >= pair_exposure_pct cap', function () {
    $rulesYaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk:
    pair_exposure_pct: 10
execution: {}
cooldowns: {}
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Fake ledger that reports 25% exposure for EUR/USD
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
            return 25.0;
        }
    };

    app()->instance(PositionLedgerContract::class, $fake);

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);

    expect($res['action'])->toBe('hold');
    expect($res['reasons'])->toContain('pair_exposure_cap');

    // Restore default ledger binding
    app()->bind(\App\Domain\Execution\PositionLedgerContract::class, function () {
        return new \App\Domain\Execution\NullPositionLedger;
    });
});
