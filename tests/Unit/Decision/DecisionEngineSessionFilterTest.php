<?php

declare(strict_types=1);

use App\Domain\Decision\DecisionEngine;
use App\Domain\Rules\AlphaRules;

it('holds during avoided Asian session', function () {
    // Create rules YAML with session filtering
    $rulesYaml = <<<'YAML'
schema_version: "1.1"
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.32
        strong: 0.50
confluence: {}
risk:
    default: 0.009
    strong_news: 0.018
execution: {}
cooldowns: {}
overrides: {}
session_filters:
    default:
        avoid_sessions:
            asian:
                start: "22:00"
                end: "06:00"
YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Create test context during Asian session (2 AM GMT)
    $ctx = [
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

    // Mock current time to 2 AM GMT (within Asian session)
    $asianTime = new DateTimeImmutable('2025-01-01 02:00:00', new DateTimeZone('UTC'));

    // Use reflection to test private method isOptimalTradingSession
    $engine = new DecisionEngine;
    $reflection = new ReflectionClass($engine);
    $method = $reflection->getMethod('isOptimalTradingSession');
    $method->setAccessible(true);

    $isOptimal = $method->invokeArgs($engine, [$asianTime, $rules]);
    expect($isOptimal)->toBeFalse();

    // Clean up temp file
    unlink($path);
});

it('allows trading during London session', function () {
    // Create rules YAML with session filtering
    $rulesYaml = <<<'YAML'
schema_version: "1.1"
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.32
        strong: 0.50
confluence: {}
risk:
    default: 0.009
    strong_news: 0.018
execution: {}
cooldowns: {}
overrides: {}
session_filters:
    default:
        avoid_sessions:
            asian:
                start: "22:00"
                end: "06:00"
YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Mock current time to 10 AM GMT (London session)
    $londonTime = new DateTimeImmutable('2025-01-01 10:00:00', new DateTimeZone('UTC'));

    // Use reflection to test private method
    $engine = new DecisionEngine;
    $reflection = new ReflectionClass($engine);
    $method = $reflection->getMethod('isOptimalTradingSession');
    $method->setAccessible(true);

    $isOptimal = $method->invokeArgs($engine, [$londonTime, $rules]);
    expect($isOptimal)->toBeTrue();

    // Clean up temp file
    unlink($path);
});

it('allows trading when no session filters configured', function () {
    // Create rules YAML without session filtering
    $rulesYaml = <<<'YAML'
schema_version: "1.1"
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.32
        strong: 0.50
confluence: {}
risk:
    default: 0.009
    strong_news: 0.018
execution: {}
cooldowns: {}
overrides: {}
YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    $anyTime = new DateTimeImmutable('2025-01-01 02:00:00', new DateTimeZone('UTC'));

    // Use reflection to test private method
    $engine = new DecisionEngine;
    $reflection = new ReflectionClass($engine);
    $method = $reflection->getMethod('isOptimalTradingSession');
    $method->setAccessible(true);

    $isOptimal = $method->invokeArgs($engine, [$anyTime, $rules]);
    expect($isOptimal)->toBeTrue();

    // Clean up temp file
    unlink($path);
});
