<?php

declare(strict_types=1);

use App\Application\Candles\DatabaseCandleProvider;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns database candles in chronological order', function () {
    $provider = new DatabaseCandleProvider;

    $now = now('UTC')->setSeconds(0);

    for ($i = 0; $i < 5; $i++) {
        Candle::create([
            'pair' => 'EUR/USD',
            'interval' => '5min',
            'timestamp' => $now->copy()->subMinutes(($i + 1) * 5),
            'open' => 1.1000 + ($i * 0.0001),
            'high' => 1.1005 + ($i * 0.0001),
            'low' => 1.0995 + ($i * 0.0001),
            'close' => 1.1002 + ($i * 0.0001),
            'volume' => 1000 + $i,
        ]);
    }

    $bars = $provider->sync('EUR/USD', '5min', 5);

    expect($bars)->toHaveCount(5);
    expect($bars[0])->toBeInstanceOf(\App\Domain\Market\Bar::class);
    expect($bars[0]->ts->getTimestamp())->toBeLessThan($bars[4]->ts->getTimestamp());
});
