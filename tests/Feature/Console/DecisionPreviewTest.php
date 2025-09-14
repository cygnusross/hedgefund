<?php

declare(strict_types=1);

use App\Application\ContextBuilder;
use App\Domain\Decision\DecisionEngine;
use App\Domain\Rules\AlphaRules;

it('prints JSON and returns non-zero when strict and hold', function () {
    // Fake ContextBuilder to return deterministic context
    $fakeCtx = [
        'meta' => ['pair_norm' => 'EUR-USD', 'data_age_sec' => 10],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1000, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5, 'sentiment' => ['long_pct' => 50, 'short_pct' => 50]],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.0, 'direction' => 'neutral'],
        'calendar' => ['within_blackout' => false],
    ];

    $this->instance(ContextBuilder::class, new class($fakeCtx)
    {
        private array $ctx;

        public function __construct(array $ctx)
        {
            $this->ctx = $ctx;
        }

        public function build(string $pair, \DateTimeImmutable $ts, mixed $newsDateOrDays = null, bool $fresh = false, bool $forceSpread = false, array $opts = []): ?array
        {
            return $this->ctx;
        }
    });

    // Use real AlphaRules instance backed by a minimal temp YAML so DecisionEngine type-hint is satisfied
    $tmp = sys_get_temp_dir().'/alpharules_test_'.uniqid().'.yml';
    file_put_contents($tmp, "gates: {}\nconfluence: {}\nrisk: {}\nexecution: {}\ncooldowns: {}\noverrides: {}\n");
    $rules = new AlphaRules($tmp);
    $rules->reload();
    $this->instance(AlphaRules::class, $rules);

    $this->artisan('decision:preview', ['pair' => 'EUR/USD', '--strict' => true])
        ->expectsOutputToContain('"decision"')
        ->assertExitCode(2);
});
