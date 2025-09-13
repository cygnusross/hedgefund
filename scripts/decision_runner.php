<?php

require __DIR__.'/../vendor/autoload.php';

use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\DecisionEngine;
use App\Domain\Market\FeatureSet;
use App\Domain\Rules\AlphaRules;

function makeCtx(array $overrides = [])
{
    $ts = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $features = new FeatureSet($ts, 1.0, 0.1, 0.0, 10.0, 5.0, 'flat', [], []);
    $meta = ['data_age_sec' => $overrides['data_age_sec'] ?? 0];
    $ctx = new DecisionContext('EUR/USD', $ts, $features, $meta);
    $arr = $ctx->toArray();
    $arr['market'] = array_merge(['status' => $overrides['status'] ?? 'TRADEABLE', 'spread_estimate_pips' => $overrides['spread'] ?? 1.0], $overrides['market'] ?? []);
    $arr['blackout'] = $overrides['blackout'] ?? false;
    $arr['calendar'] = ['within_blackout' => $overrides['within_blackout'] ?? false];

    return new class($arr)
    {
        private array $payload;

        public function __construct(array $p)
        {
            $this->payload = $p;
        }

        public function toArray(): array
        {
            return $this->payload;
        }
    };
}

$rules = new AlphaRules(__DIR__.'/../tests/Unit/Decision/fixtures/empty_rules.yaml');
$engine = new DecisionEngine;

$cases = [
    'status_closed' => ['status' => 'CLOSED'],
    'no_spread' => ['spread' => null],
    'stale_data' => ['data_age_sec' => 9999],
    'blackout' => ['within_blackout' => true],
    'ok' => [],
];

foreach ($cases as $k => $over) {
    $ctx = makeCtx($over);
    $res = $engine->decide($ctx, $rules);
    echo "$k => ".json_encode($res)."\n";
}
