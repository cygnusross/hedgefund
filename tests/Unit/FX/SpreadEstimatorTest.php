<?php

use App\Domain\FX\SpreadEstimator;
use App\Services\IG\Client as IgClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;

// No global cache helper use — tests instantiate an ArrayStore-backed Repository per case.

it('computes spread pips for EUR/USD and caches result', function () {
    // Provide a marketFinder closure that returns an object with epic without touching DB
    $marketFinder = fn (string $p) => (object) ['epic' => 'CS.D.EURUSD.MINI.IP'];

    $mockIg = Mockery::mock(IgClient::class);
    $mockIg->shouldReceive('get')->once()->andReturn(['body' => [
        'snapshot' => ['bid' => '1.17200', 'offer' => '1.17208'],
        'tradingStatus' => 'TRADEABLE',
    ]]);

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $est = new SpreadEstimator($mockIg, new CacheRepository(new ArrayStore), $logger, $marketFinder);

    $res = $est->estimatePipsForPair('EUR/USD');

    expect($res)->toBe(0.8);

    // Second call should hit cache; mock should not be called again
    $res2 = $est->estimatePipsForPair('EUR/USD');
    expect($res2)->toBe(0.8);
});

it('computes spread pips for USD/JPY and caches result', function () {
    $marketFinder = fn (string $p) => (object) ['epic' => 'CS.D.USDJPY.MINI.IP'];

    $mockIg = Mockery::mock(IgClient::class);
    $mockIg->shouldReceive('get')->once()->andReturn(['body' => [
        'snapshot' => ['bid' => '147.800', 'offer' => '147.808'],
        'tradingStatus' => 'TRADEABLE',
    ]]);

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $est = new SpreadEstimator($mockIg, new CacheRepository(new ArrayStore), $logger, $marketFinder);

    $res = $est->estimatePipsForPair('USD/JPY');

    expect($res)->toBe(0.8);
});

it('corrects scaled mini contract prices without delimiters', function () {
    $marketFinder = fn (string $p) => (object) ['epic' => 'CS.D.EURUSD.MINI.IP'];

    $mockIg = Mockery::mock(IgClient::class);
    $mockIg->shouldReceive('get')->once()->andReturn(['body' => [
        'snapshot' => ['bid' => '117200', 'offer' => '117208'],
        'tradingStatus' => 'TRADEABLE',
    ]]);

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $est = new SpreadEstimator($mockIg, new CacheRepository(new ArrayStore), $logger, $marketFinder);

    $res = $est->estimatePipsForPair('EURUSD');

    expect($res)->toBe(0.8);
});

it('returns null and logs when snapshot missing', function () {
    $marketFinder = fn (string $p) => (object) ['epic' => 'CS.D.EURUSD.MINI.IP'];

    $mockIg = Mockery::mock(IgClient::class);
    $mockIg->shouldReceive('get')->once()->andReturn(['body' => [
        // missing snapshot
        'tradingStatus' => 'TRADEABLE',
    ]]);

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $est = new SpreadEstimator($mockIg, new CacheRepository(new ArrayStore), $logger, $marketFinder);
    $res = $est->estimatePipsForPair('EUR/USD');

    expect($res)->toBeNull();
});

it('returns null for non-tradeable status', function () {
    $marketFinder = fn (string $p) => (object) ['epic' => 'CS.D.EURUSD.MINI.IP'];

    $mockIg = Mockery::mock(IgClient::class);
    $mockIg->shouldReceive('get')->once()->andReturn(['body' => [
        'snapshot' => ['bid' => '1.17200', 'offer' => '1.17208'],
        'tradingStatus' => 'SUSPENDED',
    ]]);

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $est = new SpreadEstimator($mockIg, new CacheRepository(new ArrayStore), $logger, $marketFinder);
    $res = $est->estimatePipsForPair('EUR/USD');

    expect($res)->toBeNull();
});
