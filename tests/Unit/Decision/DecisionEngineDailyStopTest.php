<?php

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;

it('holds when todays pnl <= -daily_stop', function () {
    $rulesYaml = <<<'YAML'
gates:
    daily_loss_stop_pct: 2.0
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

    // Fake ledger that returns -3.0% PnL
    $fake = new class implements PositionLedgerContract
    {
        public function todaysPnLPct(): float
        {
            return -3.0;
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

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules, null, $fake);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('hold');
    expect($res['reasons'])->toContain('daily_loss_stop');
});
