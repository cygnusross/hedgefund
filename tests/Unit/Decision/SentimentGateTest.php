<?php

use App\Domain\Decision\SentimentGate;
use App\Domain\Rules\AlphaRules;

uses(Tests\TestCase::class);

it('blocks buy when long_pct exceeds contrarian threshold', function () {
    $gate = new SentimentGate(new AlphaRules(sys_get_temp_dir() . '/alpha_rules_test.yaml'));

    $sent = ['long_pct' => 80, 'short_pct' => 20];
    $rules = ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]];

    $res = $gate->evaluateWithRules($sent, 'buy', $rules);

    expect($res)->toBeArray();
    expect($res['blocked'])->toBeTrue();
    expect($res['reason'])->toBe('contrarian_crowd_long');
});

it('does not block when in neutral band', function () {
    $gate = new SentimentGate(new AlphaRules(sys_get_temp_dir() . '/alpha_rules_test.yaml'));

    $sent = ['long_pct' => 50, 'short_pct' => 50];
    $rules = ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]];

    $res = $gate->evaluateWithRules($sent, 'buy', $rules);

    expect($res)->toBeNull();
});

it('does not block when sentiment is null', function () {
    $rules = new AlphaRules(sys_get_temp_dir() . '/alpha_rules_test.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['sentiment' => ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]]]]);

    $gate = new SentimentGate($rules);

    expect($gate->evaluate(null, 'buy'))->toBeNull();
});

it('blocks when crowd is strongly aligned with proposed action using long/short keys', function () {
    $rules = new AlphaRules(sys_get_temp_dir() . '/alpha_rules_test.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['sentiment' => ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]]]]);

    $gate = new SentimentGate($rules);

    $payload = ['marketId' => 'EUR-USD', 'long' => 70, 'short' => 30];
    $res = $gate->evaluate($payload, 'buy');
    expect($res)->not->toBeNull();
    expect($res['blocked'])->toBeTrue();
    expect($res['reason'])->toBe('contrarian_crowd_long');
});
