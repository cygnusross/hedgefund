<?php

declare(strict_types=1);

use App\Domain\Decision\DecisionEngine;
use App\Domain\Rules\AlphaRules;

it('holds when ADX below configured minimum', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['adx_min' => 20]]);

    // Use a plain features array for tests; DecisionEngine will read numeric keys used below
    $featuresArr = ['ema20' => 1.0, 'atr5m' => 0.1, 'ema20_z' => 0.0, 'adx5m' => 0.0, 'trend30m' => 'flat'];

    $payload = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 1],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'spread_estimate_pips' => 0.5],
        'features' => $featuresArr,
        'news' => ['strength' => 0.0, 'direction' => 'neutral'],
        'calendar' => ['within_blackout' => false],
    ];

    // inject a low adx into the payload
    $arr = $payload;
    $arr['features']['adx5m'] = 10;

    $engine = new DecisionEngine;
    $res = $engine->decide(new class($arr)
    {
        private $p;

        public function __construct($p)
        {
            $this->p = $p;
        }

        public function toArray()
        {
            return $this->p;
        }
    }, $rules);

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('low_adx');
});

it('holds when ema z stretched beyond configured max', function () {
    $rules = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rules);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rules, ['gates' => ['z_abs_max' => 1.0]]);

    $featuresArr = ['ema20' => 1.0, 'atr5m' => 0.1, 'ema20_z' => 0.0, 'adx5m' => 0.0, 'trend30m' => 'flat'];

    $payload = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 1],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'spread_estimate_pips' => 0.5],
        'features' => $featuresArr,
        'news' => ['strength' => 0.0, 'direction' => 'neutral'],
        'calendar' => ['within_blackout' => false],
    ];

    $arr = $payload;
    $arr['features']['ema20_z'] = 1.5;

    $engine = new DecisionEngine;
    $res = $engine->decide(new class($arr)
    {
        private $p;

        public function __construct($p)
        {
            $this->p = $p;
        }

        public function toArray()
        {
            return $this->p;
        }
    }, $rules);

    expect($res['action'])->toBe('hold');
    expect(is_array($res['reasons']))->toBeTrue();
    expect($res['reasons'])->toContain('stretched_z');
});
