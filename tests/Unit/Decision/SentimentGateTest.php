<?php

use App\Domain\Decision\SentimentGate;
use App\Domain\Rules\AlphaRules;

uses(Tests\TestCase::class);

it('blocks buy when long_pct exceeds contrarian threshold', function () {
    $gate = new SentimentGate(new AlphaRules(sys_get_temp_dir().'/alpha_rules_test.yaml'));

    $sent = ['long_pct' => 80, 'short_pct' => 20];
    $rules = ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]];

    $res = $gate->evaluateWithRules($sent, 'buy', $rules);

    expect($res)->toBeArray();
    expect($res['blocked'])->toBeTrue();
    expect($res['reason'])->toBe('contrarian_crowd_long');
});
it('blocks and unblocks according to sentiment rules', function () {
    $rules = new AlphaRules(sys_get_temp_dir().'/alpha_rules_test.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['sentiment' => ['mode' => 'contrarian', 'contrarian_threshold_pct' => 65, 'neutral_band_pct' => [45, 55]]]]);

    $gate = new SentimentGate($rules);

    // Case A: buy where long_pct = 70 -> blocked contrarian_crowd_long
    $payloadA = ['marketId' => 'EUR-USD', 'long_pct' => 70, 'short_pct' => 30];
    $resA = $gate->evaluate($payloadA, 'buy');
    expect($resA)->toBeArray();
    expect($resA['blocked'])->toBeTrue();
    expect($resA['reason'])->toBe('contrarian_crowd_long');

    // Case B: sell where short = 72 -> blocked contrarian_crowd_short
    $payloadB = ['marketId' => 'EUR-USD', 'long' => 28, 'short' => 72];
    $resB = $gate->evaluate($payloadB, 'sell');
    expect($resB)->toBeArray();
    expect($resB['blocked'])->toBeTrue();
    expect($resB['reason'])->toBe('contrarian_crowd_short');

    // Case C: neutral band 49/51 -> null
    $payloadC = ['marketId' => 'EUR-USD', 'long_pct' => 49, 'short_pct' => 51];
    $resC = $gate->evaluate($payloadC, 'buy');
    expect($resC)->toBeNull();

    // Case D: null sentiment -> null
    expect($gate->evaluate(null, 'buy'))->toBeNull();
});
