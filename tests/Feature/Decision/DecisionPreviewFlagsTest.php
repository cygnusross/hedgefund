<?php

declare(strict_types=1);

use App\Application\ContextBuilder;
use App\Domain\Rules\AlphaRules;

it('forwards --force-news and --force-calendar to ContextBuilder', function () {
    $capturer = new class
    {
        public array $lastBuildArgs = [];

        public function build(string $pair, \DateTimeImmutable $ts, mixed $newsDateOrDays = null, bool $fresh = false, bool $forceSpread = false, array $opts = []): ?array
        {
            $this->lastBuildArgs = compact('pair', 'ts', 'newsDateOrDays', 'fresh', 'forceSpread', 'opts');

            return [
                'meta' => ['pair_norm' => strtoupper(str_replace('/', '-', $pair)), 'data_age_sec' => 1],
                'market' => ['status' => 'TRADEABLE', 'last_price' => 1.1, 'atr5m_pips' => 10, 'spread_estimate_pips' => 0.1],
                'features' => [],
                'news' => ['strength' => 0.0, 'direction' => 'neutral'],
                'calendar' => ['within_blackout' => false],
            ];
        }
    };

    // Install our capturer into the container so DecisionPreview resolves it
    $this->instance(ContextBuilder::class, $capturer);

    // Provide a minimal AlphaRules instance so DecisionPreview can resolve it
    $tmp = sys_get_temp_dir().'/alpharules_test_'.uniqid().'.yml';
    file_put_contents($tmp, "gates: {}\nconfluence: {}\nrisk: {}\nexecution: {}\ncooldowns: {}\noverrides: {}\n");
    $rules = new AlphaRules($tmp);
    $rules->reload();
    $this->instance(AlphaRules::class, $rules);

    $this->artisan('decision:preview', ['pair' => 'EUR/USD', '--force-news' => true, '--force-calendar' => true])
        ->assertExitCode(0);

    // Inspect the capturer to ensure flags forwarded
    expect($capturer->lastBuildArgs)->toBeArray();
    expect($capturer->lastBuildArgs['opts']['force_news'] ?? false)->toBeTrue();
    expect($capturer->lastBuildArgs['opts']['force_calendar'] ?? false)->toBeTrue();
});
