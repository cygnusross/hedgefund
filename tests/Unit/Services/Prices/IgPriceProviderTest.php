<?php

declare(strict_types=1);

use App\Models\Market;
use App\Services\IG\Endpoints\HistoricalPricesEndpoint;
use App\Services\IG\Enums\Resolution;
use App\Services\Prices\IgPriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    \Mockery::close();
});

function createMarket(string $symbol, string $epic, float $priceScale = 10000): Market
{
    return Market::create([
        'name' => $symbol,
        'symbol' => $symbol,
        'epic' => $epic,
        'currencies' => ['base' => substr($symbol, 0, 3), 'quote' => substr($symbol, -3)],
        'price_scale' => $priceScale,
        'is_active' => true,
    ]);
}

it('normalizes IG historical prices using market price scale when raw values are large', function () {
    createMarket('EUR/GBP', 'CS.D.EURGBP.MINI.IP', 10000);

    $endpoint = \Mockery::mock(HistoricalPricesEndpoint::class);

    $response = [
        'allowance' => ['allowanceExpiry' => 1, 'remainingAllowance' => 1, 'totalAllowance' => 1],
        'instrumentType' => 'CURRENCIES',
        'prices' => [
            [
                'snapshotTime' => '2025/09/19 16:05:00',
                'openPrice' => ['bid' => 8718.0, 'ask' => 8720.0],
                'closePrice' => ['bid' => 8719.0, 'ask' => 8721.0],
                'highPrice' => ['bid' => 8725.0, 'ask' => 8727.0],
                'lowPrice' => ['bid' => 8710.0, 'ask' => 8712.0],
                'lastTradedVolume' => 100.0,
            ],
        ],
    ];

    $endpoint->shouldReceive('get')
        ->once()
        ->with('CS.D.EURGBP.MINI.IP', Resolution::MINUTE_5, 50)
        ->andReturn($response);

    $provider = new IgPriceProvider($endpoint);

    $bars = $provider->getCandles('EUR/GBP', ['interval' => '5min', 'outputsize' => 50]);

    expect($bars)->toHaveCount(1);
    expect($bars[0]->close)->toBe(0.8719);
    expect($bars[0]->open)->toBe(0.8718);
});

it('leaves prices unchanged when values are already in decimal form', function () {
    createMarket('EUR/GBP', 'CS.D.EURGBP.MINI.IP', 10000);

    $endpoint = \Mockery::mock(HistoricalPricesEndpoint::class);

    $response = [
        'allowance' => ['allowanceExpiry' => 1, 'remainingAllowance' => 1, 'totalAllowance' => 1],
        'instrumentType' => 'CURRENCIES',
        'prices' => [
            [
                'snapshotTime' => '2025/09/19 16:05:00',
                'openPrice' => ['bid' => 0.8718, 'ask' => 0.8720],
                'closePrice' => ['bid' => 0.8719, 'ask' => 0.8721],
                'highPrice' => ['bid' => 0.8725, 'ask' => 0.8727],
                'lowPrice' => ['bid' => 0.8710, 'ask' => 0.8712],
                'lastTradedVolume' => 100.0,
            ],
        ],
    ];

    $endpoint->shouldReceive('get')
        ->once()
        ->with('CS.D.EURGBP.MINI.IP', Resolution::MINUTE_5, 10)
        ->andReturn($response);

    $provider = new IgPriceProvider($endpoint);

    $bars = $provider->getCandles('EUR/GBP', ['interval' => '5min', 'outputsize' => 10]);

    expect($bars)->toHaveCount(1);
    expect($bars[0]->close)->toBe(0.8719);
    expect($bars[0]->open)->toBe(0.8718);
});
