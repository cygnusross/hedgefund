<?php

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;
use App\Support\Clock\ClockInterface;

it('holds when within cooldown after loss', function () {
    $rulesYaml = <<<'YAML'
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.3
        strong: 0.45
confluence: {}
risk: {}
execution: {}
cooldowns:
    after_loss_minutes: 60
    after_win_minutes: 5
overrides: {}
YAML;
    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $tenMinutesAgo = $now->sub(new \DateInterval('PT10M'));

    // Fake ledger that returns a recent loss
    $fake = new class($tenMinutesAgo) implements PositionLedgerContract
    {
        private \DateTimeImmutable $ts;

        public function __construct(\DateTimeImmutable $ts)
        {
            $this->ts = $ts;
        }

        public function todaysPnLPct(): float
        {
            return 0.0;
        }

        public function lastTrade(): ?array
        {
            return ['outcome' => 'loss', 'ts' => $this->ts];
        }

        public function openPositionsCount(): int
        {
            return 0;
        }

        public function pairExposurePct(string $pair): float
        {
            return 0.0;
        }
    };

    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10],
        'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.5],
        'features' => ['trend30m' => 'up'],
        'news' => ['strength' => 0.5, 'direction' => 'buy'],
        'calendar' => ['within_blackout' => false],
    ];

    $clock = new class($now) implements ClockInterface
    {
        public function __construct(private DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };

    $engine = new LiveDecisionEngine($rules, $clock, $fake);
    $res = $engine->decide(DecisionRequest::fromArray($ctx))->toArray();

    expect($res['action'])->toBe('hold');
    expect($res['reasons'])->toContain('cooldown_active');
});
