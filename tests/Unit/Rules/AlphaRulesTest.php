<?php

use App\Domain\Rules\AlphaRules;

beforeEach(function () {
    // Ensure storage testing dir exists
    $tmpDir = sys_get_temp_dir().'/hedgefund_testing';
    if (! is_dir($tmpDir)) {
        mkdir($tmpDir.'/framework/testing', 0777, true);
    }
});

it('loads and validates a valid yaml', function () {
    $yaml = <<<'YAML'
gates:
  adx_min: 20
  news_threshold:
    strong: 3
    moderate: 2
    deadband: 1
confluence: {}
risk:
  per_trade_pct:
    default: 1.0
    strong: 2.0
    medium_strong: 1.5
    moderate: 1.0
    weak: 0.5
  per_trade_cap_pct: 2
execution:
  rr: 2.0
  spread_ceiling_pips: 20
cooldowns: {}
overrides: {}
schema_version: '1.0'
YAML;

    $tmpDir = sys_get_temp_dir().'/hedgefund_testing';
    $path = $tmpDir.'/framework/testing/alpha_rules.yaml';
    file_put_contents($path, $yaml);

    $r = new AlphaRules($path);
    $r->reload();

    expect($r->meta()['checksum'])->not->toBeEmpty();
    expect($r->getGate('adx_min'))->toBe(20);
    expect($r->getRisk('per_trade_pct.medium_strong'))->toBe(1.5);
    expect($r->getExecution('rr'))->toBe(2.0);
    expect($r->get('overrides'))->toBe([]);
});

it('throws when required keys missing', function () {
    $yaml = "gates: {}\n";
    $tmpDir = sys_get_temp_dir().'/hedgefund_testing';
    $path = $tmpDir.'/framework/testing/alpha_rules_invalid.yaml';
    file_put_contents($path, $yaml);

    $r = new AlphaRules($path);
    $r->reload();
})->throws(RuntimeException::class);
