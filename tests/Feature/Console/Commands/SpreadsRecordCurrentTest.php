<?php

declare(strict_types=1);

use App\Domain\FX\Contracts\SpreadEstimatorContract;
use App\Models\Market;
use App\Models\Spread;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create specific test markets with unique data
    Market::create([
        'epic' => 'CS.D.EURUSD.TODAY.IP',
        'name' => 'EUR/USD',
        'symbol' => 'EUR/USD',
        'currencies' => ['EUR', 'USD'],
        'is_active' => true,
    ]);

    Market::create([
        'epic' => 'CS.D.GBPUSD.TODAY.IP',
        'name' => 'GBP/USD',
        'symbol' => 'GBP/USD',
        'currencies' => ['GBP', 'USD'],
        'is_active' => true,
    ]);

    Market::create([
        'epic' => 'CS.D.USDJPY.TODAY.IP',
        'name' => 'USD/JPY',
        'symbol' => 'USD/JPY',
        'currencies' => ['USD', 'JPY'],
        'is_active' => true,
    ]);

    // Create a complete mock to prevent ANY real API calls
    $mockSpreadEstimator = \Mockery::mock(SpreadEstimatorContract::class);

    // Bind the mock in the container to replace the real service completely
    $this->app->instance(SpreadEstimatorContract::class, $mockSpreadEstimator);

    // Store reference so individual tests can add expectations
    $this->mockSpreadEstimator = $mockSpreadEstimator;
});

it('successfully records spreads for all markets', function () {
    // Mock spread estimates for each market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('EUR/USD', true)
        ->once()
        ->andReturn(1.2);

    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('GBP/USD', true)
        ->once()
        ->andReturn(1.5);

    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('USD/JPY', true)
        ->once()
        ->andReturn(0.8);

    artisan('spreads:record-current')
        ->expectsOutput('Recording current spread data for all markets...')
        ->expectsOutput('Recorded spread for CS.D.EURUSD.TODAY.IP: 1.2 pips')
        ->expectsOutput('Recorded spread for CS.D.GBPUSD.TODAY.IP: 1.5 pips')
        ->expectsOutput('Recorded spread for CS.D.USDJPY.TODAY.IP: 0.8 pips')
        ->expectsOutput('Successfully recorded spreads for 3 markets.')
        ->assertSuccessful();

    // Verify spreads were recorded in database
    expect(Spread::count())->toBe(3);

    expect(Spread::where('pair', 'CS.D.EURUSD.TODAY.IP')->first())
        ->spread_pips->toEqual(1.2)
        ->recorded_at->toBeInstanceOf(Carbon\Carbon::class);

    expect(Spread::where('pair', 'CS.D.GBPUSD.TODAY.IP')->first())
        ->spread_pips->toEqual(1.5);

    expect(Spread::where('pair', 'CS.D.USDJPY.TODAY.IP')->first())
        ->spread_pips->toEqual(0.8);
});

it('handles spreads that fail to retrieve', function () {
    // Mock successful spread for first market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('EUR/USD', true)
        ->once()
        ->andReturn(1.2);

    // Mock failure for second market (returns null)
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('GBP/USD', true)
        ->once()
        ->andReturn(null);

    // Mock successful spread for third market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('USD/JPY', true)
        ->once()
        ->andReturn(0.8);

    artisan('spreads:record-current')
        ->expectsOutput('Recording current spread data for all markets...')
        ->expectsOutput('Recorded spread for CS.D.EURUSD.TODAY.IP: 1.2 pips')
        ->expectsOutput('Failed to get spread for CS.D.GBPUSD.TODAY.IP')
        ->expectsOutput('Recorded spread for CS.D.USDJPY.TODAY.IP: 0.8 pips')
        ->expectsOutput('Successfully recorded spreads for 2 markets.')
        ->assertExitCode(1); // Should fail because of errors

    // Verify only successful spreads were recorded
    expect(Spread::count())->toBe(2);

    expect(Spread::where('pair', 'CS.D.EURUSD.TODAY.IP')->exists())->toBeTrue();
    expect(Spread::where('pair', 'CS.D.GBPUSD.TODAY.IP')->exists())->toBeFalse();
    expect(Spread::where('pair', 'CS.D.USDJPY.TODAY.IP')->exists())->toBeTrue();
});

it('handles case when no markets exist', function () {
    // Delete all markets
    Market::truncate();

    // No methods should be called on the estimator
    $this->mockSpreadEstimator->shouldNotReceive('estimatePipsForPair');

    artisan('spreads:record-current')
        ->expectsOutput('Recording current spread data for all markets...')
        ->expectsOutput('Successfully recorded spreads for 0 markets.')
        ->assertSuccessful();

    expect(Spread::count())->toBe(0);
});

it('handles spreads estimator exception gracefully', function () {
    // Mock successful spread for first market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('EUR/USD', true)
        ->once()
        ->andReturn(1.2);

    // Mock exception for second market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('GBP/USD', true)
        ->once()
        ->andThrow(new Exception('API connection failed'));

    // Mock successful spread for third market
    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('USD/JPY', true)
        ->once()
        ->andReturn(0.8);

    artisan('spreads:record-current')
        ->expectsOutput('Recording current spread data for all markets...')
        ->expectsOutput('Recorded spread for CS.D.EURUSD.TODAY.IP: 1.2 pips')
        ->expectsOutput('Failed to get spread for CS.D.GBPUSD.TODAY.IP')
        ->expectsOutput('Recorded spread for CS.D.USDJPY.TODAY.IP: 0.8 pips')
        ->expectsOutput('Successfully recorded spreads for 2 markets.')
        ->assertExitCode(1); // Should fail because of errors

    // Verify spreads were recorded except for the failed one
    expect(Spread::count())->toBe(2);
    expect(Spread::where('pair', 'CS.D.GBPUSD.TODAY.IP')->exists())->toBeFalse();
});

it('records spreads with accurate timestamps', function () {
    $beforeTime = now();

    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('EUR/USD', true)
        ->once()
        ->andReturn(1.2);

    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('GBP/USD', true)
        ->once()
        ->andReturn(1.5);

    $this->mockSpreadEstimator->shouldReceive('estimatePipsForPair')
        ->with('USD/JPY', true)
        ->once()
        ->andReturn(0.8);

    artisan('spreads:record-current')->assertSuccessful();

    $afterTime = now();

    $spreads = Spread::all();

    expect($spreads)->toHaveCount(3);

    foreach ($spreads as $spread) {
        expect($spread->recorded_at->toDateTimeString())
            ->toBeGreaterThanOrEqual($beforeTime->toDateTimeString())
            ->toBeLessThanOrEqual($afterTime->toDateTimeString());
    }
});
