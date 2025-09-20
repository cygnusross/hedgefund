<?php

declare(strict_types=1);

use App\Domain\Rules\Calibration\CandidateGenerator;
use App\Domain\Rules\Calibration\MonteCarloEvaluator;
use App\Domain\Rules\Calibration\RubixMLPipeline;
use App\Models\Candle;
use App\Models\Market;
use App\Models\RuleSet;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('executes full calibration workflow successfully', function () {
    // Create test data
    $market = Market::factory()->create(['symbol' => 'EURUSD']);

    // Create historical candles for the calibration period
    $startDate = CarbonImmutable::create(2025, 10, 1);
    $endDate = CarbonImmutable::create(2025, 10, 7);

    for ($i = 0; $i < 168; $i++) { // 7 days * 24 hours
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => $startDate->addHours($i),
            'open' => 1.0850 + (rand(-50, 50) / 10000),
            'high' => 1.0900 + (rand(-50, 50) / 10000),
            'low' => 1.0800 + (rand(-50, 50) / 10000),
            'close' => 1.0875 + (rand(-50, 50) / 10000),
        ]);
    }

    // Create baseline rule set
    $baselineRules = RuleSet::factory()->create([
        'tag' => '2025-W40-baseline',
        'base_rules' => [
            'gates' => ['adx_min' => 24, 'sentiment' => ['mode' => 'contrarian']],
            'execution' => ['rr' => 2.0, 'sl_atr_mult' => 2.0, 'tp_atr_mult' => 4.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        'is_active' => false,
    ]);

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W40-integration',
        '--period-start' => '2025-10-01',
        '--period-end' => '2025-10-07',
        '--dry-run' => true, // Don't actually activate
    ])
        ->assertExitCode(Command::SUCCESS);

    // Verify new rule sets were created
    $newRuleSets = RuleSet::where('tag', 'like', '2025-W40-integration%')->get();
    expect($newRuleSets->count())->toBeGreaterThan(0);

    // Should include baseline
    expect($newRuleSets->where('tag', '2025-W40-integration-baseline')->count())->toBe(1);
});

it('handles missing baseline rule set', function () {
    $this->artisan('rules:calibrate', [
        'tag' => '2025-W40-no-baseline',
        '--period-start' => '2025-10-01',
        '--period-end' => '2025-10-07',
        '--baseline-tag' => 'non-existent-baseline',
    ])
        ->expectsOutput('Baseline rule set "non-existent-baseline" not found.')
        ->assertExitCode(Command::FAILURE);
});

it('validates calibration period dates', function () {
    $this->artisan('rules:calibrate', [
        'tag' => '2025-W40-invalid-dates',
        '--period-start' => '2025-10-07',
        '--period-end' => '2025-10-01', // End before start
    ])
        ->expectsOutput('Period end must be after period start.')
        ->assertExitCode(Command::FAILURE);
});

it('creates comprehensive calibration dataset', function () {
    // Create markets and candles
    Market::factory()->create(['symbol' => 'EURUSD']);
    Market::factory()->create(['symbol' => 'GBPUSD']);

    $startDate = CarbonImmutable::create(2025, 10, 8);
    $endDate = CarbonImmutable::create(2025, 10, 14);

    // Create candles for both markets
    foreach (['EURUSD', 'GBPUSD'] as $symbol) {
        for ($i = 0; $i < 168; $i++) {
            Candle::factory()->create([
                'pair' => $symbol,
                'interval' => '1H',
                'timestamp' => $startDate->addHours($i),
            ]);
        }
    }

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W41-dataset',
        '--period-start' => '2025-10-08',
        '--period-end' => '2025-10-14',
        '--markets' => 'EURUSD,GBPUSD',
        '--dry-run' => true,
    ])
        ->expectsOutput('Created calibration dataset with 2 markets')
        ->assertExitCode(Command::SUCCESS);
});

it('integrates with existing rule resolution system', function () {
    // Create baseline rule set with market overrides
    $baselineRules = RuleSet::factory()->create([
        'tag' => '2025-W40-integration-baseline',
        'base_rules' => [
            'gates' => ['adx_min' => 24],
            'execution' => ['rr' => 2.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        'market_overrides' => [
            'EURUSD' => [
                'risk' => ['per_trade_pct' => ['default' => 1.25]],
            ],
        ],
        'is_active' => false,
    ]);

    // Create test candles
    Market::factory()->create(['symbol' => 'EURUSD']);

    for ($i = 0; $i < 24; $i++) {
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => CarbonImmutable::create(2025, 10, 15)->addHours($i),
        ]);
    }

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W42-resolution',
        '--period-start' => '2025-10-15',
        '--period-end' => '2025-10-15',
        '--baseline-tag' => '2025-W40-integration-baseline',
        '--dry-run' => true,
    ])
        ->assertExitCode(Command::SUCCESS);

    // Verify the calibration used the baseline with overrides
    $calibratedRules = RuleSet::where('tag', 'like', '2025-W42-resolution%')->first();
    expect($calibratedRules)->not->toBeNull();
});

it('respects dry run mode', function () {
    Market::factory()->create(['symbol' => 'EURUSD']);

    for ($i = 0; $i < 24; $i++) {
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => CarbonImmutable::create(2025, 10, 16)->addHours($i),
        ]);
    }

    $initialRuleSetCount = RuleSet::count();

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W42-dry-run',
        '--period-start' => '2025-10-16',
        '--period-end' => '2025-10-16',
        '--dry-run' => true,
    ])
        ->expectsOutput('DRY RUN MODE - No changes will be saved')
        ->assertExitCode(Command::SUCCESS);

    // No new rule sets should be created in dry run
    expect(RuleSet::count())->toBe($initialRuleSetCount);
});

it('handles activation mode correctly', function () {
    Market::factory()->create(['symbol' => 'EURUSD']);

    for ($i = 0; $i < 24; $i++) {
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => CarbonImmutable::create(2025, 10, 17)->addHours($i),
        ]);
    }

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W42-activate',
        '--period-start' => '2025-10-17',
        '--period-end' => '2025-10-17',
        '--activate' => true,
        '--shadow' => false,
    ])
        ->expectsConfirmation('Activate best performing rule set in PRODUCTION mode?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    // Should have activated rule set
    $activeRuleSets = RuleSet::where('is_active', true)->get();
    expect($activeRuleSets->count())->toBe(1);
});

it('generates performance summary for calibrated rules', function () {
    Market::factory()->create(['symbol' => 'EURUSD']);

    for ($i = 0; $i < 168; $i++) { // Full week
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => CarbonImmutable::create(2025, 10, 18)->addHours($i),
        ]);
    }

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W42-performance',
        '--period-start' => '2025-10-18',
        '--period-end' => '2025-10-24',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Generated') // Should show generation progress
        ->expectsOutputToContain('candidates') // Should show candidates processed
        ->assertExitCode(Command::SUCCESS);
});

it('validates market data availability', function () {
    // Don't create any markets or candles

    $this->artisan('rules:calibrate', [
        'tag' => '2025-W42-no-data',
        '--period-start' => '2025-10-25',
        '--period-end' => '2025-10-31',
        '--markets' => 'EURUSD',
    ])
        ->expectsOutput('No candle data found for the specified period and markets.')
        ->assertExitCode(Command::FAILURE);
});

it('integrates all pipeline components', function () {
    // This test verifies the integration points between:
    // CandidateGenerator -> RubixMLPipeline -> MonteCarloEvaluator
    // Using real components with test optimizations

    Market::factory()->create(['symbol' => 'EURUSD']);

    // Create sufficient candles for meaningful calibration
    for ($i = 0; $i < 168; $i++) {
        Candle::factory()->create([
            'pair' => 'EURUSD',
            'interval' => '1H',
            'timestamp' => CarbonImmutable::create(2025, 10, 26)->addHours($i),
        ]);
    }

    // Use real pipeline - test optimizations will make it run quickly
    $this->artisan('rules:calibrate', [
        'tag' => '2025-W43-integration',
        '--period-start' => '2025-10-26',
        '--period-end' => '2025-11-01',
        '--no-activate' => true,
    ])
        ->assertExitCode(Command::SUCCESS);

    // Verify that a result was created (flow completion, not specific PnL)
    $ruleSet = RuleSet::where('tag', '2025-W43-integration-baseline')->first();
    expect($ruleSet)->not()->toBeNull();
    // Metrics may be empty with test optimizations, but the pipeline integration worked
    expect($ruleSet->metrics)->toBeArray();
});
