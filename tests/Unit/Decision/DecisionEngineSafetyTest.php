<?php

use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\DecisionEngine;
use App\Domain\Market\FeatureSet;
use App\Domain\Rules\AlphaRules;

function makeContext(array $overrides = []): mixed
{
    $ts = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $features = new FeatureSet(
        $ts,
        1.0,
        0.1,
        0.0,
        10.0,
        5.0,
        'flat',
        [],
        [],
    );

    $meta = ['data_age_sec' => $overrides['data_age_sec'] ?? 0];
    $ctx = new DecisionContext('EUR/USD', $ts, $features, $meta);
    $arr = $ctx->toArray();

    // Merge market/defaults and then rehydrate via DecisionContext is unnecessary for tests — but
    // we'll place additional keys into the array and then create a lightweight wrapper object to
    // pass into DecisionEngine via DecisionContext (Test uses toArray internally).
    $arr['market'] = array_merge(['status' => $overrides['status'] ?? 'TRADEABLE', 'spread_estimate_pips' => $overrides['spread'] ?? 1.0], $overrides['market'] ?? []);
    $arr['blackout'] = $overrides['blackout'] ?? false;
    $arr['calendar'] = ['within_blackout' => $overrides['within_blackout'] ?? false];

    // Create a lightweight object with toArray() to satisfy DecisionEngine
    return new class($arr)
    {
        private array $payload;

        public function __construct(array $payload)
        {
            $this->payload = $payload;
        }

        public function toArray(): array
        {
            return $this->payload;
        }
    };
}

function makeRules(array $overrides = []): AlphaRules
{
    $path = __DIR__.'/fixtures/empty_rules.yaml';
    $rules = new AlphaRules($path);

    // Avoid calling reload in tests; use getGate with defaults or simulate by setting internal data.
    return $rules;
}

it('blocks when market status not allowed', function () {
    $ctx = makeContext(['status' => 'CLOSED']);
    $rules = makeRules();
    // override required statuses to ['TRADEABLE'] by default
    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);
    expect($res['action'])->toBe('hold');
    expect($res['reason'])->toBe('status_closed');
});

it('allows when market status allowed', function () {
    $ctx = makeContext(['status' => 'TRADEABLE']);
    $rules = makeRules();
    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);
    expect($res['reason'])->toBe('ok');
});

it('blocks when spread required but missing', function () {
    $ctx = makeContext(['spread' => null]);
    $rules = makeRules();
    // Force spread_required gate by creating a rules object that returns true for the gate
    // Named test subclass to override getGate
    if (! class_exists('\Tests\Unit\Decision\TestAlphaRulesSpread')) {
        class TestAlphaRulesSpread extends AlphaRules
        {
            public function __construct()
            {
                parent::__construct(__DIR__.'/fixtures/empty_rules.yaml');
            }

            public function getGate(string $key, $default = null)
            {
                if ($key === 'spread_required') {
                    return true;
                }

                return parent::getGate($key, $default);
            }
        }
    }
    // Use a real AlphaRules instance and inject the gate via reflection to avoid
    // extending the final AlphaRules class.
    $rulesWith = new AlphaRules(__DIR__.'/fixtures/empty_rules.yaml');
    $ref = new ReflectionClass($rulesWith);
    $prop = $ref->getProperty('data');
    $prop->setAccessible(true);
    $prop->setValue($rulesWith, ['gates' => ['spread_required' => true]]);

    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rulesWith);
    expect($res['reason'])->toBe('no_spread');
});

it('blocks when data is stale', function () {
    $ctx = makeContext(['data_age_sec' => 9999]);
    $rules = makeRules();
    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);
    expect($res['reason'])->toBe('stale_data');
});

it('blocks on calendar blackout', function () {
    $ctx = makeContext(['within_blackout' => true]);
    $rules = makeRules();
    $engine = new DecisionEngine;
    $res = $engine->decide($ctx, $rules);
    expect($res['reason'])->toBe('blackout');
});
