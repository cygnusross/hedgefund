<?php

declare(strict_types=1);

use App\Models\RuleSet;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('activates rule set in production mode', function () {
    // Create a test rule set
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-test',
        'base_rules' => [
            'gates' => ['adx_min' => 24],
            'execution' => ['rr' => 2.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        'market_overrides' => [],
        'is_active' => false,
        'metrics' => [
            'total_pnl' => 150.0,
            'win_rate' => 0.67,
            'max_drawdown_pct' => 8.5,
            'trades_total' => 45,
        ],
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-test',
        '--production' => true,
    ])
        ->expectsConfirmation('Activate rule set 2025-W45-test in PRODUCTION mode?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    // Verify rule set was activated
    $ruleSet->refresh();
    expect($ruleSet->is_active)->toBeTrue();
    expect($ruleSet->activated_at)->not->toBeNull();
});

it('activates rule set in shadow mode', function () {
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-shadow',
        'base_rules' => ['gates' => ['adx_min' => 26]],
        'is_active' => false,
        'shadow_mode' => false,
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-shadow',
        '--shadow' => true,
    ])
        ->expectsConfirmation('Activate rule set 2025-W45-shadow in SHADOW mode?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    $ruleSet->refresh();
    expect($ruleSet->is_active)->toBeTrue();
    expect($ruleSet->shadow_mode)->toBeTrue();
});

it('shows detailed rule set summary before activation', function () {
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-summary',
        'base_rules' => [
            'gates' => [
                'adx_min' => 28,
                'sentiment' => ['mode' => 'contrarian'],
            ],
            'execution' => [
                'rr' => 2.5,
                'sl_atr_mult' => 2.0,
                'tp_atr_mult' => 5.0,
            ],
            'risk' => [
                'per_trade_pct' => ['default' => 1.25],
            ],
        ],
        'market_overrides' => [
            'EURUSD' => [
                'risk' => ['per_trade_pct' => ['default' => 1.5]],
            ],
        ],
        'metrics' => [
            'total_pnl' => 275.50,
            'win_rate' => 0.72,
            'max_drawdown_pct' => 6.8,
            'trades_total' => 68,
            'sharpe_ratio' => 1.65,
            'profit_factor' => 2.1,
        ],
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-summary',
        '--production' => true,
    ])
        ->expectsOutputToContain('Rule Set: 2025-W45-summary')
        ->expectsOutputToContain('ADX Minimum: 28')
        ->expectsOutputToContain('Sentiment Mode: contrarian')
        ->expectsOutputToContain('Risk-Reward Ratio: 2.5')
        ->expectsOutputToContain('Total PnL: 275.5')
        ->expectsOutputToContain('Win Rate: 72.00%')
        ->expectsOutputToContain('Max Drawdown: 6.8%')
        ->expectsOutputToContain('Total Trades: 68')
        ->expectsOutputToContain('Market Overrides: 1')
        ->expectsConfirmation('Activate rule set 2025-W45-summary in PRODUCTION mode?', 'no')
        ->assertExitCode(Command::SUCCESS);
});

it('handles missing rule set gracefully', function () {
    $this->artisan('rules:activate', [
        'tag' => 'non-existent-tag',
        '--production' => true,
    ])
        ->expectsOutput('Rule set with tag "non-existent-tag" not found.')
        ->assertExitCode(Command::FAILURE);
});

it('deactivates other active rule sets when activating new one', function () {
    // Create two rule sets, with one already active
    $activeRuleSet = RuleSet::factory()->create([
        'tag' => '2025-W44-old',
        'is_active' => true,
        'activated_at' => CarbonImmutable::now()->subDays(7),
    ]);

    $newRuleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-new',
        'is_active' => false,
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-new',
        '--production' => true,
    ])
        ->expectsConfirmation('Activate rule set 2025-W45-new in PRODUCTION mode?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    // Old rule set should be deactivated
    $activeRuleSet->refresh();
    expect($activeRuleSet->is_active)->toBeFalse();
    expect($activeRuleSet->deactivated_at)->not->toBeNull();

    // New rule set should be active
    $newRuleSet->refresh();
    expect($newRuleSet->is_active)->toBeTrue();
});

it('allows canceling activation', function () {
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-cancel',
        'is_active' => false,
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-cancel',
        '--production' => true,
    ])
        ->expectsConfirmation('Activate rule set 2025-W45-cancel in PRODUCTION mode?', 'no')
        ->expectsOutput('Activation cancelled.')
        ->assertExitCode(Command::SUCCESS);

    // Rule set should remain inactive
    $ruleSet->refresh();
    expect($ruleSet->is_active)->toBeFalse();
});

it('validates production mode requires confirmation', function () {
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-prod',
        'is_active' => false,
    ]);

    // Test that production mode requires explicit confirmation
    $this->artisan('rules:activate', [
        'tag' => '2025-W45-prod',
        '--production' => true,
    ])
        ->expectsConfirmation('Activate rule set 2025-W45-prod in PRODUCTION mode?', 'yes')
        ->assertExitCode(Command::SUCCESS);
});

it('shows appropriate warnings for production activation', function () {
    $ruleSet = RuleSet::factory()->create([
        'tag' => '2025-W45-warning',
        'metrics' => [
            'total_pnl' => -45.0, // Negative PnL
            'win_rate' => 0.42,
            'max_drawdown_pct' => 18.5, // High drawdown
            'trades_total' => 12, // Low trade count
        ],
    ]);

    $this->artisan('rules:activate', [
        'tag' => '2025-W45-warning',
        '--production' => true,
    ])
        ->expectsOutput('⚠️  Warning: This rule set has negative total PnL (-45)')
        ->expectsOutput('⚠️  Warning: This rule set has high drawdown (18.5%)')
        ->expectsOutput('⚠️  Warning: This rule set has limited trading history (12 trades)')
        ->expectsConfirmation('Activate rule set 2025-W45-warning in PRODUCTION mode?', 'no')
        ->assertExitCode(Command::SUCCESS);
});
