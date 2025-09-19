<?php

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
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

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'ig_rules' => ['pip_value' => 1.0, 'size_step' => 0.01]],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules, null, $fake);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    // Risk: 1% of 10000 = 100; slPips = sl_atr_mult * atr = 2 * 10 = 20; pip_value=1 => rawSize=100/(20*1)=5
    expect($res['size'])->toBe(5.0);
});

it('demonstrates account balance integration with position sizing', function () {
    // Test the Risk\Sizing class directly with different account balances
    // This verifies that position sizing scales correctly with account size

    $riskPct = 1.0; // 1% risk
    $slPips = 20.0; // 20 pips stop loss
    $pipValue = 1.0; // £1 per pip
    $sizeStep = 0.01; // minimum increment

    // Primary Trading Sleeve (£50,000)
    $primarySize = \App\Domain\Risk\Sizing::computeStake(50000.0, $riskPct, $slPips, $pipValue, $sizeStep);

    // Conservative Sleeve (£25,000)
    $conservativeSize = \App\Domain\Risk\Sizing::computeStake(25000.0, $riskPct, $slPips, $pipValue, $sizeStep);

    // Default/old hardcoded (£10,000)
    $defaultSize = \App\Domain\Risk\Sizing::computeStake(10000.0, $riskPct, $slPips, $pipValue, $sizeStep);

    // Verify position sizes scale with account balance
    expect($primarySize)->toBe(25.0); // 1% of £50k / 20 pips / £1 per pip
    expect($conservativeSize)->toBe(12.5); // 1% of £25k / 20 pips / £1 per pip
    expect($defaultSize)->toBe(5.0); // 1% of £10k / 20 pips / £1 per pip

    // Verify proportional scaling
    expect($primarySize)->toBe($defaultSize * 5); // 50k is 5x larger than 10k
    expect($conservativeSize)->toBe($defaultSize * 2.5); // 25k is 2.5x larger than 10k
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

    // For USD/JPY, pip_value may be different — set pip_value to 0.9 to simulate
    $ctx = [
        'meta' => ['pair_norm' => 'USDJPY', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 154.2, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'ig_rules' => ['pip_value' => 0.9, 'size_step' => 0.01]],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules, null, $fake);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    // RiskAmount = 100; slPips = 20; pip_value = 0.9 -> rawSize = 100/(20*0.9) = 5.555... -> floor to 5.55 step 0.01
    expect($res['size'])->toBe(5.55);
});
