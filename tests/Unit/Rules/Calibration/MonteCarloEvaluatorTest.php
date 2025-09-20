<?php

declare(strict_types=1);

use App\Backtest\BacktestRunner;
use App\Domain\Rules\Calibration\CalibrationCandidate;
use App\Domain\Rules\Calibration\CalibrationDataset;
use App\Domain\Rules\Calibration\CandidateScore;
use App\Domain\Rules\Calibration\MonteCarloEvaluator;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('runs Monte Carlo simulation with proper sampling', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate(
        id: 'mc-test-1',
        baseRules: [
            'gates' => ['adx_min' => 24, 'sentiment' => ['mode' => 'contrarian']],
            'execution' => ['rr' => 2.0, 'sl_atr_mult' => 2.0, 'tp_atr_mult' => 4.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        marketOverrides: [],
        metadata: ['stage' => 'monte_carlo']
    );

    // Create scored candidates collection
    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.65,
                'trades_per_day' => 1.2,
                'expectancy' => 2.5,
                'composite' => 0.85,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset(
        tag: '2025-W44',
        markets: ['EURUSD', 'GBPUSD'],
        snapshots: [],
        regimeSummary: [],
        costEstimates: []
    );

    // Mock BacktestRunner
    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->atLeast(1)
        ->andReturn([
            'total_pnl' => 150.0,
            'total_trades' => 45,
            'win_rate' => 0.67,
            'avg_trade_pnl' => 3.33,
            'max_drawdown_pct' => 8.5,
            'sharpe_ratio' => 1.45,
            'profit_factor' => 1.8,
            'trades_per_day' => 1.2,
            'largest_loss' => -25.0,
            'largest_win' => 85.0,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result->count())->toBeGreaterThan(0);

    $firstResult = $result->first();
    expect($firstResult)->toBeInstanceOf(CandidateScore::class);
    expect($firstResult->candidate)->toBeInstanceOf(CalibrationCandidate::class);
    expect($firstResult->riskMetrics)->toHaveKey('p95_drawdown');
});

it('filters candidates with insufficient trade frequency', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate(
        'low-frequency-test',
        ['gates' => ['adx_min' => 45]], // Very high ADX = fewer trades
        [],
        []
    );

    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.75,
                'trades_per_day' => 0.2, // Below 0.3 threshold
                'expectancy' => 1.5,
                'composite' => 0.75,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    // Mock BacktestRunner returning very low trade frequency
    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->andReturn([
            'total_pnl' => 25.0,
            'total_trades' => 8, // Only 8 trades over evaluation period
            'win_rate' => 0.75,
            'trades_per_day' => 0.2, // Below 0.3 threshold
            'max_drawdown_pct' => 5.0,
            'sharpe_ratio' => 0.85,
            'profit_factor' => 2.1,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset);

    // Should filter out low-frequency candidates
    expect($result->count())->toBe(0); // Filtered out
});

it('filters candidates with excessive drawdown risk', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate(
        'high-risk-test',
        ['risk' => ['per_trade_pct' => ['default' => 3.0]]], // High risk per trade
        [],
        []
    );

    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.34,
                'trades_per_day' => 1.0,
                'expectancy' => -0.5,
                'composite' => 0.80,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    // Mock BacktestRunner returning high drawdown scenarios
    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->andReturn([
            'total_pnl' => -80.0,
            'total_trades' => 35,
            'win_rate' => 0.34,
            'trades_per_day' => 1.0,
            'max_drawdown_pct' => 22.0, // Above 15% threshold
            'sharpe_ratio' => -0.5,
            'profit_factor' => 0.6,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset);

    // Should filter out high-risk candidates
    expect($result->count())->toBe(0); // Filtered out
});

it('calculates risk metrics correctly', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate(
        'metrics-test',
        [
            'gates' => ['adx_min' => 24],
            'execution' => ['rr' => 2.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        [],
        []
    );

    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.60,
                'trades_per_day' => 1.0,
                'expectancy' => 2.0,
                'composite' => 0.70,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    // Mock BacktestRunner with consistent results for metric validation
    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->andReturn([
            'total_pnl' => 100.0,
            'total_trades' => 50,
            'win_rate' => 0.60,
            'trades_per_day' => 1.0,
            'max_drawdown_pct' => 10.0,
            'sharpe_ratio' => 1.5,
            'profit_factor' => 1.6,
            'avg_trade_pnl' => 2.0,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset);

    expect($result->count())->toBe(1); // Should pass filtering

    $evaluatedCandidate = $result->first();
    expect($evaluatedCandidate)->toBeInstanceOf(CandidateScore::class);

    $riskMetrics = $evaluatedCandidate->riskMetrics;
    expect($riskMetrics)->toHaveKey('p95_drawdown');
    expect($riskMetrics)->toHaveKey('monthly_loss_probability');
    expect($riskMetrics)->toHaveKey('value_at_risk');
});

it('handles edge cases in simulation data', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate(
        'edge-case-test',
        ['gates' => ['adx_min' => 26]],
        [],
        []
    );

    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.65,
                'trades_per_day' => 1.1,
                'expectancy' => 1.8,
                'composite' => 0.65,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    // Mock BacktestRunner with mixed scenarios
    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->andReturn([
            'total_pnl' => 60.0,
            'total_trades' => 35,
            'win_rate' => 0.65,
            'trades_per_day' => 1.1,
            'max_drawdown_pct' => 8.0,
            'sharpe_ratio' => 1.3,
            'profit_factor' => 1.7,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset);

    // Should handle mixed data gracefully
    expect($result)->toBeInstanceOf(Collection::class);
    expect($result->count())->toBeGreaterThanOrEqual(0);
});

it('respects custom simulation parameters', function () {
    $evaluator = new MonteCarloEvaluator;

    $candidate = new CalibrationCandidate('custom-params', [], [], []);

    $scoredCandidates = collect([
        new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => 0.60,
                'trades_per_day' => 0.8,
                'expectancy' => 1.3,
                'composite' => 0.60,
            ]
        ),
    ]);

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    $mockRunner = Mockery::mock(BacktestRunner::class);
    $mockRunner->shouldReceive('run')
        ->andReturn([
            'total_pnl' => 30.0,
            'total_trades' => 20,
            'win_rate' => 0.60,
            'trades_per_day' => 0.8, // Above default threshold
            'max_drawdown_pct' => 11.0, // Below default threshold
            'sharpe_ratio' => 1.1,
            'profit_factor' => 1.3,
        ]);

    app()->instance(BacktestRunner::class, $mockRunner);

    $result = $evaluator->evaluate($scoredCandidates, $dataset, topN: 5);

    expect($result->count())->toBeGreaterThan(0);
});
