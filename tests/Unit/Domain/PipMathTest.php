<?php

declare(strict_types=1);

use App\Domain\FX\PipMath;

it('calculates pip size and conversions for EUR/USD', function () {
    $pair = 'EUR/USD';
    expect(PipMath::pipSize($pair))->toBe(0.0001);
    expect(PipMath::toPips(0.0010, $pair))->toBe(10.0);
    expect(PipMath::fromPips(10.0, $pair))->toBe(0.001);
});

it('calculates pip size and conversions for USD/JPY', function () {
    $pair = 'USD/JPY';
    expect(PipMath::pipSize($pair))->toBe(0.01);
    expect(PipMath::toPips(0.20, $pair))->toBe(20.0);
    expect(PipMath::fromPips(20.0, $pair))->toBe(0.2);
});
