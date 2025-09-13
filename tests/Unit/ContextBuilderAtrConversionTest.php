<?php

use App\Domain\FX\PipMath;

it('converts ATR to pips for EUR/USD', function () {
    $atr = 0.0003; // EUR/USD
    $pips = PipMath::toPips($atr, 'EUR/USD');
    expect(round($pips, 1))->toBe(3.0);
});

it('converts ATR to pips for USD/JPY', function () {
    $atr = 0.30; // USD/JPY
    $pips = PipMath::toPips($atr, 'USD/JPY');
    expect($pips)->toBe(30.0);
});
