<?php

declare(strict_types=1);

use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Rules\AlphaRules;

it('allows trading with reduced confidence during avoided sessions', function () {
    // Create rules YAML with session filtering
    $rulesYaml = <<<'YAML'
schema_version: "1.1"
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.32
        strong: 0.50
    market_required_status: ["TRADEABLE"]
    spread_required: false
    max_data_age_sec: 600
confluence: {}
risk:
    default: 0.009
    strong_news: 0.018
execution:
    sl_atr_mult: 2.0
    tp_atr_mult: 4.0
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

    // Create context with strong trading signal during Asian session
    $ctx = [
        'meta' => ['pair_norm' => 'USDJPY', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 146.304,
            'atr5m_pips' => 5.6,
            'spread_estimate_pips' => 0.6,
            'sentiment' => ['long_pct' => 62.0, 'short_pct' => 38.0],
            'ig_rules' => ['pip_value' => 1.0, 'size_step' => 0.01],
        ],
        'features' => [
            'trend30m' => 'up',
            'adx5m' => 39.0, // Strong trend
            'ema20_z' => -0.95, // Oversold
        ],
        'news' => ['strength' => 0.75, 'direction' => 'buy'], // Strong news
        'calendar' => ['within_blackout' => false],
    ];

    // Test at 2 AM GMT (Asian session - avoid period)
    $engine = new LiveDecisionEngine($rules);

    // Mock the current time to be in Asian session by overriding the DateTime creation
    // We'll use a custom context object that provides the timestamp
    $contextWithTime = new class($ctx)
    {
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function toArray(): array
        {
            // Add a timestamp that falls in the Asian session (2 AM GMT)
            $this->data['ts'] = '2025-01-01T02:00:00+00:00';

            return $this->data;
        }
    };

    $result = $engine->decide(DecisionRequest::fromArray($contextWithTime->toArray()), $rules)->toArray();

    // Verify the decision allows trading but with reduced confidence
    expect($result['action'])->toBe('buy');
    expect($result['blocked'] ?? false)->toBeFalse();
    expect($result['confidence'])->toBeGreaterThan(0.0);
    expect($result['confidence'])->toBeLessThan(0.75); // Reduced from original 0.75 due to session penalty
    expect($result['news_label'] ?? null)->toBe('strong');
    expect($result['reasons'])->toContain('session_timing_penalty');
    expect($result['reasons'])->toContain('ok');

    // Clean up temp file
    unlink($path);
});

it('allows trading with boosted confidence during preferred sessions', function () {
    // Create rules YAML with preferred session
    $rulesYaml = <<<'YAML'
schema_version: "1.1"
gates:
    news_threshold:
        deadband: 0.1
        moderate: 0.32
        strong: 0.50
    market_required_status: ["TRADEABLE"]
    spread_required: false
    max_data_age_sec: 600
confluence: {}
risk:
    default: 0.009
    strong_news: 0.018
execution:
    sl_atr_mult: 2.0
    tp_atr_mult: 4.0
cooldowns: {}
overrides: {}
session_filters:
    default:
        preferred_sessions:
            london_ny_overlap:
                start: "13:00"
                end: "17:00"
YAML;

    $path = tempnam(sys_get_temp_dir(), 'alpharules').'.yaml';
    file_put_contents($path, $rulesYaml);
    $rules = new AlphaRules($path);
    $rules->reload();

    // Create context with moderate trading signal during preferred session
    $ctx = [
        'meta' => ['pair_norm' => 'EURUSD', 'data_age_sec' => 10, 'sleeve_balance' => 10000.0],
        'market' => [
            'status' => 'TRADEABLE',
            'last_price' => 1.1000,
            'atr5m_pips' => 2.8,
            'spread_estimate_pips' => 0.9,
            'sentiment' => ['long_pct' => 60.0, 'short_pct' => 40.0],
            'ig_rules' => ['pip_value' => 1.0, 'size_step' => 0.01],
        ],
        'features' => [
            'trend30m' => 'up',
            'adx5m' => 25.0, // Moderate trend
            'ema20_z' => 0.5,
        ],
        'news' => ['strength' => 0.4, 'direction' => 'buy'], // Moderate news
        'calendar' => ['within_blackout' => false],
    ];

    // Test at 14:00 GMT (London-NY overlap - preferred period)
    $engine = new LiveDecisionEngine($rules);

    $contextWithTime = new class($ctx)
    {
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function toArray(): array
        {
            // Add a timestamp that falls in the preferred session (14:00 GMT)
            $this->data['ts'] = '2025-01-01T14:00:00+00:00';

            return $this->data;
        }
    };

    $result = $engine->decide(DecisionRequest::fromArray($contextWithTime->toArray()), $rules)->toArray();

    // Verify the decision allows trading with boosted confidence
    expect($result['action'])->toBe('buy');
    expect($result['blocked'] ?? false)->toBeFalse();
    expect($result['confidence'])->toBeGreaterThan(0.4); // Boosted from original 0.4 due to session bonus
    expect($result['news_label'] ?? null)->toBe('moderate');
    expect($result['reasons'])->toContain('session_timing_boost');
    expect($result['reasons'])->toContain('ok');

    // Clean up temp file
    unlink($path);
});
