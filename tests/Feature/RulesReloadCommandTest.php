<?php

it('reloads rules and shows values', function () {
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

    $path = storage_path('framework/testing/alpha_rules_cmd.yaml');
    file_put_contents($path, $yaml);

    putenv('RULES_YAML_PATH='.$path);

    $this->artisan('rules:reload --show')->expectsOutputToContain('checksum')->assertExitCode(0);
    $this->artisan('rules:reload --show')->expectsOutputToContain('risk.per_trade_cap_pct')->assertExitCode(0);
});
