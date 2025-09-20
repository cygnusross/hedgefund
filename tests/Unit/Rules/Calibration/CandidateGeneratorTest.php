<?php

declare(strict_types=1);

use App\Domain\Rules\Calibration\CalibrationConfig;
use App\Domain\Rules\Calibration\CalibrationDataset;
use App\Domain\Rules\Calibration\CandidateGenerator;
use App\Domain\Rules\ResolvedRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('generates comprehensive coarse grid with parameter constraints', function () {
    // Override test config for this specific test to test actual grid generation volume
    config([
        'calibration.budgets.stage1_count' => 300, // Use production level for this test
    ]);

    $generator = new CandidateGenerator;

    // Create baseline rules
    $baseRules = [
        'gates' => [
            'adx_min' => 24,
            'sentiment' => ['mode' => 'contrarian'],
        ],
        'execution' => [
            'risk' => [
                'per_trade_pct' => ['default' => 1.0, 'risk_free_lookback_days' => 21],
            ],
            'rr' => ['take_profit' => 1.5, 'stop_loss' => 1.0],
        ],
    ];

    $baseline = new ResolvedRules(
        base: $baseRules,
        marketOverrides: [],
        emergencyOverrides: [],
        metadata: [],
        tag: 'test-baseline'
    );

    // Create calibration config
    $config = new CalibrationConfig(
        tag: 'test-candidate-gen',
        periodStart: CarbonImmutable::parse('2023-01-01'),
        periodEnd: CarbonImmutable::parse('2023-01-31'),
        dryRun: true,
        markets: ['EURUSD', 'GBPUSD'],
    );

    $dataset = new CalibrationDataset('test', ['EURUSD', 'GBPUSD'], [], [], []);

    $candidates = $generator->generate($config, $baseline, $dataset);

    // Filter only grid candidates (exclude baseline)
    $gridCandidates = $candidates->filter(fn($c) => ($c->metadata['stage'] ?? null) === 'grid');

    // Should have diverse parameter combinations
    $adxValues = $gridCandidates->pluck('metadata.adx_min')->filter()->unique();
    $sentimentModes = $gridCandidates->pluck('metadata.sentiment')->filter()->unique();
    $rrValues = $gridCandidates->pluck('metadata.rr')->filter()->unique();

    // Should have diverse parameter combinations

    // Should generate comprehensive grid with good parameter coverage
    expect($candidates)->toBeInstanceOf(Collection::class);
    expect($candidates->count())->toBeGreaterThan(50); // At least substantial grid
    expect($candidates->count())->toBeLessThan(350); // But not excessive

    // First candidate should be baseline
    $first = $candidates->first();
    expect($first->id)->toContain('baseline');

    // Should have parameter diversity in the grid
    expect($adxValues->count())->toBeGreaterThan(2); // Multiple ADX values
    expect($sentimentModes->count())->toBeGreaterThan(1); // Multiple sentiment modes
    expect($rrValues->count())->toBeGreaterThanOrEqual(2); // Multiple risk/reward ratios
});

it('validates parameter constraints during generation', function () {
    $generator = new CandidateGenerator;

    // Test invalid RR constraints
    $invalidRules = [
        'execution' => [
            'rr' => ['take_profit' => 0.5, 'stop_loss' => 1.0], // TP < SL (invalid)
        ],
    ];

    $baseline = new ResolvedRules(
        base: $invalidRules,
        marketOverrides: [],
        emergencyOverrides: [],
        metadata: [],
        tag: 'test-invalid'
    );
    $config = new CalibrationConfig(
        tag: 'test-invalid',
        periodStart: CarbonImmutable::parse('2023-01-01'),
        periodEnd: CarbonImmutable::parse('2023-01-31'),
        dryRun: true,
        markets: ['EURUSD'],
    );

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    $candidates = $generator->generate($config, $baseline, $dataset);

    // Should still generate baseline + some valid grid candidates (filtering out invalid ones)
    expect($candidates->count())->toBeGreaterThan(0);

    // All generated candidates should have valid RR structures (invalid baseline may be included)
    $validCandidates = $candidates->filter(function ($candidate) {
        $execution = $candidate->baseRules['execution'] ?? [];

        // Check if it has the old-style structure with take_profit/stop_loss
        if (isset($execution['rr']['take_profit']) && isset($execution['rr']['stop_loss'])) {
            $tp = $execution['rr']['take_profit'];
            $sl = $execution['rr']['stop_loss'];
            return $tp > $sl; // Filter for valid ones only
        }
        // Check if it has the new-style structure with RR ratio and ATR multipliers
        elseif (isset($execution['rr']) && is_numeric($execution['rr'])) {
            $rr = $execution['rr'];
            if ($rr <= 0) return false;

            // If ATR multipliers are present, TP should be > SL
            if (isset($execution['tp_atr_mult']) && isset($execution['sl_atr_mult'])) {
                return $execution['tp_atr_mult'] > $execution['sl_atr_mult'];
            }
            return true; // RR ratio is valid
        }

        return true; // Unknown structure, assume valid
    });

    // Should have some valid candidates even with invalid baseline
    expect($validCandidates->count())->toBeGreaterThan(0);
});

it('generates refinements around top candidates', function () {
    $generator = new CandidateGenerator;

    $baseRules = [
        'gates' => ['adx_min' => 20],
        'execution' => [
            'risk' => ['per_trade_pct' => ['default' => 2.0]],
            'rr' => ['take_profit' => 2.0, 'stop_loss' => 1.0],
        ],
    ];

    $baseline = new ResolvedRules(
        base: $baseRules,
        marketOverrides: [],
        emergencyOverrides: [],
        metadata: [],
        tag: 'test-refinements'
    );
    $config = new CalibrationConfig(
        tag: 'test-refinements',
        periodStart: CarbonImmutable::parse('2023-01-01'),
        periodEnd: CarbonImmutable::parse('2023-01-31'),
        dryRun: true,
        markets: ['EURUSD'],
    );

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    $candidates = $generator->generate($config, $baseline, $dataset);

    expect($candidates)->toBeInstanceOf(Collection::class);
    expect($candidates->count())->toBeGreaterThan(5);

    // Should have more focused parameter ranges in stage2
    $adxValues = $candidates->pluck('rules.gates.adx_min')->filter()->unique();
    expect($adxValues->count())->toBeLessThan(10); // More focused than stage1
});

it('maintains RR consistency in generated candidates', function () {
    $generator = new CandidateGenerator;

    $baseRules = [
        'execution' => [
            'rr' => ['take_profit' => 1.5, 'stop_loss' => 1.0],
        ],
    ];

    $baseline = new ResolvedRules(
        base: $baseRules,
        marketOverrides: [],
        emergencyOverrides: [],
        metadata: [],
        tag: 'test-rr-consistency'
    );
    $config = new CalibrationConfig(
        tag: 'test-rr-consistency',
        periodStart: CarbonImmutable::parse('2023-02-01'),
        periodEnd: CarbonImmutable::parse('2023-02-28'),
        dryRun: true,
        markets: ['GBPUSD'],
    );

    $dataset = new CalibrationDataset('test', ['GBPUSD'], [], [], []);

    $candidates = $generator->generate($config, $baseline, $dataset);

    // All candidates must maintain proper risk/reward relationships
    $candidates->each(function ($candidate) {
        $execution = $candidate->baseRules['execution'] ?? [];

        // Check if it has the old-style structure with take_profit/stop_loss
        if (isset($execution['rr']['take_profit']) && isset($execution['rr']['stop_loss'])) {
            $tp = $execution['rr']['take_profit'];
            $sl = $execution['rr']['stop_loss'];
            expect($tp)->toBeGreaterThan($sl, "Take profit ({$tp}) must be > stop loss ({$sl})");
        }
        // Check if it has the new-style structure with RR ratio and ATR multipliers
        elseif (isset($execution['rr']) && is_numeric($execution['rr'])) {
            $rr = $execution['rr'];
            expect($rr)->toBeGreaterThan(0, "Risk/reward ratio ({$rr}) must be positive");

            // If ATR multipliers are present, TP should be > SL
            if (isset($execution['tp_atr_mult']) && isset($execution['sl_atr_mult'])) {
                $tpMult = $execution['tp_atr_mult'];
                $slMult = $execution['sl_atr_mult'];
                expect($tpMult)->toBeGreaterThan($slMult, "TP ATR mult ({$tpMult}) must be > SL ATR mult ({$slMult})");
            }
        }
    });
});

it('generates unique candidate IDs', function () {
    $generator = new CandidateGenerator;

    $baseRules = [
        'gates' => ['adx_min' => 22],
        'execution' => [
            'rr' => ['take_profit' => 1.8, 'stop_loss' => 1.0],
        ],
    ];

    $baseline = new ResolvedRules(
        base: $baseRules,
        marketOverrides: [],
        emergencyOverrides: [],
        metadata: []
    );
    $config = new CalibrationConfig(
        tag: 'test-unique-ids',
        markets: ['EURUSD'],
        periodStart: CarbonImmutable::parse('2023-03-01'),
        periodEnd: CarbonImmutable::parse('2023-03-31'),
        dryRun: true,
    );

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    $candidates = $generator->generate($config, $baseline, $dataset);

    $ids = $candidates->pluck('id');
    $uniqueIds = $ids->unique();

    expect($ids->count())->toBe($uniqueIds->count());
});
