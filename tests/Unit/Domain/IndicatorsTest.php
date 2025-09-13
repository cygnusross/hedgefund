<?php

declare(strict_types=1);

use App\Domain\Indicators\Indicators;
use App\Domain\Market\Bar;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

it('returns null when insufficient data for ema and atr', function () {
    $now = new \DateTimeImmutable('2025-01-01T00:00:00Z');

    // only 5 bars
    $bars = [];
    for ($i = 0; $i < 5; $i++) {
        $bars[] = new Bar($now->modify("+{$i} minutes"), 1.0, 1.0, 1.0, 1.0, null);
    }

    expect(Indicators::ema($bars, 20))->toBeNull();
    expect(Indicators::atr($bars, 14))->toBeNull();
});

it('computes ema and atr for synthetic increasing series', function () {
    $now = new \DateTimeImmutable('2025-01-01T00:00:00Z');
    $bars = [];

    // create 50 bars with steadily increasing closes
    $open = 100.0;
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->modify('+'.($i * 5).' minutes');
        $close = $open + ($i * 0.5);
        $high = $close + 0.1;
        $low = $close - 0.1;
        $bars[] = new Bar($ts, $open, $high, $low, $close, 1000.0);
        $open = $close; // next open equals previous close
    }

    $ema = Indicators::ema($bars, 20);
    $atr = Indicators::atr($bars, 14);

    expect($ema)->toBeFloat();
    expect($atr)->toBeFloat();

    // EMA for an increasing series should be between the first and last close
    $firstClose = $bars[0]->close;
    $lastClose = end($bars)->close;
    expect($ema)->toBeGreaterThan($firstClose);
    expect($ema)->toBeLessThan($lastClose);
    expect($atr)->toBeGreaterThan(0.0);
});
