<?php

declare(strict_types=1);

use App\Domain\Execution\DecisionToIgOrderConverter;
use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Mock IG API HTTP calls to prevent real API requests
    Http::fake([
        'https://demo-api.ig.com/*' => Http::response([
            'snapshot' => [
                'bid' => 11750, // Raw format (1.1750)
                'offer' => 11755, // Raw format (1.1755)
                'netChange' => 0.0005,
                'pctChange' => 0.0042,
                'updateTime' => '10:30:00',
                'delayTime' => 0,
                'marketStatus' => 'TRADEABLE',
            ],
            'dealingRules' => [
                'minStepDistance' => ['value' => 5, 'unit' => 'POINTS'],
                'minDealSize' => ['value' => 0.5, 'unit' => 'AMOUNT'],
                'minControlledRiskStopDistance' => ['value' => 10, 'unit' => 'POINTS'],
                'minNormalStopOrLimitDistance' => ['value' => 8, 'unit' => 'POINTS'],
            ],
        ], 200),
    ]);
    // Create test markets
    Market::create([
        'symbol' => 'EUR/USD',
        'epic' => 'CS.D.EURUSD.TODAY.IP',
        'is_active' => true,
        'name' => 'EUR/USD Today',
    ]);

    Market::create([
        'symbol' => 'USD/JPY',
        'epic' => 'CS.D.USDJPY.MINI.IP',
        'is_active' => true,
        'name' => 'USD/JPY Mini',
    ]);

    Market::create([
        'symbol' => 'GBP/USD',
        'epic' => 'CS.D.GBPUSD.MINI.IP',
        'is_active' => false, // Inactive for testing
        'name' => 'GBP/USD Mini',
    ]);
});

it('converts EUR/USD buy decision to IG working order format', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 1.1768,
        'sl' => 1.1760,     // 8 points away
        'tp' => 1.1783,     // 15 points away
        'size' => 26.31,
    ];

    $result = DecisionToIgOrderConverter::convert($decision, 'EUR/USD');

    // Check basic structure
    expect($result['currencyCode'])->toBe('GBP');
    expect($result['direction'])->toBe('BUY');
    expect($result['epic'])->toBe('CS.D.EURUSD.TODAY.IP');
    expect($result['expiry'])->toBe('DFB');
    expect($result['guaranteedStop'])->toBe(false);
    expect($result['size'])->toBe(26.31);
    expect($result['timeInForce'])->toBe('GOOD_TILL_CANCELLED');
    expect($result['type'])->toBe('STOP');

    // Check that we have distance-based stops and limits (like successful manual orders)
    expect($result)->toHaveKey('stopDistance');
    expect($result)->toHaveKey('limitDistance');
    expect($result['stopDistance'])->toBeInt(); // Raw points integer
    expect($result['limitDistance'])->toBeInt(); // Raw points integer
    expect($result['level'])->toBeInt(); // Entry level in raw integer format
    expect($result['level'])->toBeGreaterThan(10000); // Should be raw format like 11816
});

it('converts EUR/USD sell decision to IG working order format', function () {
    $decision = [
        'action' => 'sell',
        'entry' => 1.1750,
        'sl' => 1.1760,     // 10 points away
        'tp' => 1.1730,     // 20 points away
        'size' => 15.5,
    ];

    $result = DecisionToIgOrderConverter::convert($decision, 'EUR/USD');

    expect($result['direction'])->toBe('SELL');
    expect($result['size'])->toBe(15.5);
    expect($result['type'])->toBe('STOP');
    // Check that we have distance-based stops and limits
    expect($result)->toHaveKey('stopDistance');
    expect($result)->toHaveKey('limitDistance');
    expect($result['stopDistance'])->toBeInt(); // Raw points integer
    expect($result['limitDistance'])->toBeInt(); // Raw points integer
    expect($result['level'])->toBeInt(); // Entry level in raw integer format
});

it('converts USD/JPY decision with correct pip calculation', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 150.00,
        'sl' => 149.50,     // 50 points away (JPY pairs use 0.01 pip size)
        'tp' => 150.75,     // 75 points away
        'size' => 10.0,
    ];

    $result = DecisionToIgOrderConverter::convert($decision, 'USD/JPY');

    expect($result['epic'])->toBe('CS.D.USDJPY.MINI.IP');
    expect($result['direction'])->toBe('BUY');
    // Check that we have distance-based stops and limits
    expect($result)->toHaveKey('stopDistance');
    expect($result)->toHaveKey('limitDistance');
    expect($result['stopDistance'])->toBeInt(); // Raw points integer
    expect($result['limitDistance'])->toBeInt(); // Raw points integer
    expect($result['level'])->toBeInt(); // Entry level in raw integer format
});

it('handles different pair formats correctly', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 1.1768,
        'sl' => 1.1760,
        'tp' => 1.1783,
        'size' => 26.31,
    ];

    // Test with dash format
    $result1 = DecisionToIgOrderConverter::convert($decision, 'EUR-USD');

    // Test with slash format
    $result2 = DecisionToIgOrderConverter::convert($decision, 'EUR/USD');

    expect($result1['epic'])->toBe($result2['epic']);
    expect($result1['stopDistance'])->toBe($result2['stopDistance']);
    expect($result1['limitDistance'])->toBe($result2['limitDistance']);
});

it('throws exception for missing required fields', function () {
    $incompleteDecision = [
        'action' => 'buy',
        'entry' => 1.1768,
        // Missing sl, tp, size
    ];

    expect(fn () => DecisionToIgOrderConverter::convert($incompleteDecision, 'EUR/USD'))
        ->toThrow(InvalidArgumentException::class, 'Decision array missing required field: sl');
});

it('throws exception for invalid action', function () {
    $decision = [
        'action' => 'hold',
        'entry' => 1.1768,
        'sl' => 1.1760,
        'tp' => 1.1783,
        'size' => 26.31,
    ];

    expect(fn () => DecisionToIgOrderConverter::convert($decision, 'EUR/USD'))
        ->toThrow(InvalidArgumentException::class, 'Invalid action: hold');
});

it('throws exception for non-numeric values', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 'invalid',
        'sl' => 1.1760,
        'tp' => 1.1783,
        'size' => 26.31,
    ];

    expect(fn () => DecisionToIgOrderConverter::convert($decision, 'EUR/USD'))
        ->toThrow(InvalidArgumentException::class, 'Field entry must be a positive numeric value');
});

it('throws exception for inactive market', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 1.2500,
        'sl' => 1.2490,
        'tp' => 1.2520,
        'size' => 10.0,
    ];

    expect(fn () => DecisionToIgOrderConverter::convert($decision, 'GBP/USD'))
        ->toThrow(InvalidArgumentException::class, 'No active market found for pair: GBP/USD');
});

it('throws exception for unknown market', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 1.5000,
        'sl' => 1.4990,
        'tp' => 1.5020,
        'size' => 10.0,
    ];

    expect(fn () => DecisionToIgOrderConverter::convert($decision, 'EUR/CHF'))
        ->toThrow(InvalidArgumentException::class, 'No active market found for pair: EUR/CHF');
});

it('calculates distances accurately with decimal precision', function () {
    $decision = [
        'action' => 'buy',
        'entry' => 1.17685,
        'sl' => 1.17601,      // 8.4 points away
        'tp' => 1.17824,      // 13.9 points away
        'size' => 26.31,
    ];

    $result = DecisionToIgOrderConverter::convert($decision, 'EUR/USD');

    // Check that distances are properly calculated
    expect($result)->toHaveKey('stopDistance');
    expect($result)->toHaveKey('limitDistance');
    expect($result['stopDistance'])->toBeInt(); // Raw points integer
    expect($result['limitDistance'])->toBeInt(); // Raw points integer
    expect($result['level'])->toBeInt(); // Entry level in raw integer format
});
