<?php

declare(strict_types=1);

use App\Domain\Market\Bar;
use App\Services\IG\DTO\HistoricalPrice;
use App\Services\IG\DTO\Price;

it('creates HistoricalPrice from array data', function () {
    $data = [
        'closePrice' => ['ask' => 1.1234, 'bid' => 1.1230, 'lastTraded' => 1.1232],
        'highPrice' => ['ask' => 1.1240, 'bid' => 1.1236, 'lastTraded' => 1.1238],
        'lowPrice' => ['ask' => 1.1220, 'bid' => 1.1216, 'lastTraded' => 1.1218],
        'openPrice' => ['ask' => 1.1225, 'bid' => 1.1221, 'lastTraded' => 1.1223],
        'snapshotTime' => '2025/09/16 12:00:00',
        'lastTradedVolume' => 1000.0,
    ];

    $historicalPrice = HistoricalPrice::fromArray($data);

    expect($historicalPrice->closePrice)->toBeInstanceOf(Price::class);
    expect($historicalPrice->closePrice->bid)->toBe(1.1230);
    expect($historicalPrice->closePrice->ask)->toBe(1.1234);
    expect($historicalPrice->snapshotTime)->toBe('2025/09/16 12:00:00');
    expect($historicalPrice->lastTradedVolume)->toBe(1000.0);
});

it('converts HistoricalPrice to Bar object', function () {
    $data = [
        'closePrice' => ['ask' => 1.1234, 'bid' => 1.1230],
        'highPrice' => ['ask' => 1.1240, 'bid' => 1.1236],
        'lowPrice' => ['ask' => 1.1220, 'bid' => 1.1216],
        'openPrice' => ['ask' => 1.1225, 'bid' => 1.1221],
        'snapshotTime' => '2025/09/16 12:00:00',
        'lastTradedVolume' => 1000.0,
    ];

    $historicalPrice = HistoricalPrice::fromArray($data);
    $bar = $historicalPrice->toBar();

    expect($bar)->toBeInstanceOf(Bar::class);
    expect($bar->open)->toBe(1.1221);
    expect($bar->high)->toBe(1.1236);
    expect($bar->low)->toBe(1.1216);
    expect($bar->close)->toBe(1.1230);
    expect($bar->volume)->toBe(1000.0);
    expect($bar->ts->format('Y-m-d H:i:s'))->toBe('2025-09-16 12:00:00');
});

it('handles missing optional fields gracefully', function () {
    $data = [
        'closePrice' => ['bid' => 1.1230],
        'highPrice' => ['bid' => 1.1236],
        'lowPrice' => ['bid' => 1.1216],
        'openPrice' => ['bid' => 1.1221],
        'snapshotTime' => '2025/09/16 12:00:00',
        // lastTradedVolume is missing
    ];

    $historicalPrice = HistoricalPrice::fromArray($data);
    $bar = $historicalPrice->toBar();

    expect($historicalPrice->lastTradedVolume)->toBeNull();
    expect($bar->volume)->toBeNull();
    expect($bar->open)->toBe(1.1221);
});

it('throws exception for invalid snapshot time format', function () {
    $data = [
        'closePrice' => ['bid' => 1.1230],
        'highPrice' => ['bid' => 1.1236],
        'lowPrice' => ['bid' => 1.1216],
        'openPrice' => ['bid' => 1.1221],
        'snapshotTime' => 'invalid-date-format',
    ];

    $historicalPrice = HistoricalPrice::fromArray($data);

    expect(fn () => $historicalPrice->toBar())
        ->toThrow(\InvalidArgumentException::class, 'Invalid snapshot time format');
});
