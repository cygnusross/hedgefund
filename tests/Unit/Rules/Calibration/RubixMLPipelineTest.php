<?php

declare(strict_types=1);

use App\Domain\Rules\Calibration\CalibrationCandidate;
use App\Domain\Rules\Calibration\CalibrationDataset;
use App\Domain\Rules\Calibration\RubixMLPipeline;
use App\Models\Market;
use Tests\TestCase;

uses(TestCase::class);

it('creates and configures machine learning model correctly', function () {
    $pipeline = new RubixMLPipeline;

    $dataset = new CalibrationDataset(
        tag: '2025-W43',
        markets: ['EURUSD', 'GBPUSD'],
        snapshots: [],
        regimeSummary: [],
        costEstimates: []
    );

    // Test model preparation - this will attempt to load or train
    expect(fn () => $pipeline->prepareModel($dataset))->not->toThrow(Exception::class);
});

it('scores candidates using machine learning model', function () {
    $pipeline = new RubixMLPipeline;

    // Create a simple mock dataset for training
    $dataset = new CalibrationDataset(
        tag: '2025-W43',
        markets: ['EURUSD'],
        snapshots: [],
        regimeSummary: [],
        costEstimates: []
    );

    $candidate = new CalibrationCandidate(
        id: '2025-W43-score-test',
        baseRules: [
            'gates' => ['adx_min' => 24, 'sentiment' => ['mode' => 'contrarian']],
            'execution' => ['rr' => 2.0, 'sl_atr_mult' => 2.0, 'tp_atr_mult' => 4.0],
            'risk' => ['per_trade_pct' => ['default' => 1.0]],
        ],
        marketOverrides: [],
        metadata: ['stage' => 'test']
    );

    // Test that scoreCandidate expects model to be prepared first
    expect(fn () => $pipeline->scoreCandidate($candidate, $dataset))
        ->toThrow(RuntimeException::class, 'Model not prepared');
});

it('validates pipeline storage path configuration', function () {
    $customPath = 'storage/test/ml_models/custom_classifier.rbx';
    $pipeline = new RubixMLPipeline($customPath);

    // Pipeline should be instantiable with custom storage path
    expect($pipeline)->toBeInstanceOf(RubixMLPipeline::class);
});

it('handles dataset with multiple markets', function () {
    $pipeline = new RubixMLPipeline;

    $dataset = new CalibrationDataset(
        tag: '2025-W43-multi',
        markets: ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD'],
        snapshots: [],
        regimeSummary: [],
        costEstimates: []
    );

    // Should handle multi-market datasets without error
    expect(fn () => $pipeline->prepareModel($dataset))->not->toThrow(Exception::class);
});

it('maintains consistent scoring interface', function () {
    $pipeline = new RubixMLPipeline;

    $dataset = new CalibrationDataset('test', ['EURUSD'], [], [], []);

    $candidate1 = new CalibrationCandidate(
        'test-1',
        ['gates' => ['adx_min' => 20]],
        [],
        []
    );

    $candidate2 = new CalibrationCandidate(
        'test-2',
        ['gates' => ['adx_min' => 28]],
        [],
        []
    );

    // Both should throw same exception when model not prepared
    expect(fn () => $pipeline->scoreCandidate($candidate1, $dataset))
        ->toThrow(RuntimeException::class);

    expect(fn () => $pipeline->scoreCandidate($candidate2, $dataset))
        ->toThrow(RuntimeException::class);
});
