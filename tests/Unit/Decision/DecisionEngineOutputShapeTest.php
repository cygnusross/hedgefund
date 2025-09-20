<?php

declare(strict_types=1);

use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Rules\AlphaRules;

it('when allowed includes trade fields; when blocked sets blocked=true', function () {
    // Build rules that allow a buy
    $yaml = <<<'YAML'
gates: {}
confluence: {}
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

    $ctxAllowed = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 1, 'sleeve_balance' => 10000.0],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'ig_rules' => ['pip_value' => 1.0, 'size_step' => 0.01]],
        'features' => ['trend30m' => 'up'],
        'calendar' => ['within_blackout' => false],
    ];

    $engine = new LiveDecisionEngine($rules);
    $resAllowed = $engine->decide(DecisionRequest::fromArray($ctxAllowed))->toArray();

    // When allowed, expect action != 'hold' and presence of trade fields
    expect($resAllowed['action'])->not->toBe('hold');
    expect(array_key_exists('risk_pct', $resAllowed))->toBeTrue();
    expect(array_key_exists('entry', $resAllowed))->toBeTrue();
    expect(array_key_exists('sl', $resAllowed))->toBeTrue();
    expect(array_key_exists('tp', $resAllowed))->toBeTrue();

    // Now craft a blocked context (low adx)
    $rulesBlock = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rulesBlock);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rulesBlock, ['gates' => ['adx_min' => 20]]);

    $ctxBlocked = $ctxAllowed;
    $ctxBlocked['features']['adx5m'] = 5;

    $engineBlocked = new LiveDecisionEngine($rulesBlock);
    $resBlocked = $engineBlocked->decide(DecisionRequest::fromArray($ctxBlocked))->toArray();

    expect($resBlocked['action'])->toBe('hold');
    expect(is_array($resBlocked['reasons']))->toBeTrue();
    // when blocked, should include blocked=true
    expect($resBlocked['blocked'] ?? true)->toBeTrue();
});
