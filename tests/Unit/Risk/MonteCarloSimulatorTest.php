<?php

declare(strict_types=1);

use App\Domain\Risk\MonteCarloSimulator;
use MathPHP\Probability\Distribution\Continuous\Normal;
use MathPHP\Statistics\Average;

it('generates paths from injected distribution', function () {
    $simulator = new MonteCarloSimulator;
    $distribution = new class([1.0, 2.0, 3.0, 4.0, 5.0, 6.0]) extends Normal
    {
        public function __construct(private array $values)
        {
            parent::__construct(0.0, 1.0);
        }

        public function rand(): float
        {
            return (float) array_shift($this->values);
        }
    };

    $paths = $simulator->simulate($distribution, 2, 3);

    expect($paths)->toBe([
        [1.0, 2.0, 3.0],
        [4.0, 5.0, 6.0],
    ]);
});

it('simulates normal paths with expected mean', function () {
    $simulator = new MonteCarloSimulator;
    $paths = $simulator->simulateNormal(25, 40, 0.5, 1.5);

    $flattened = array_merge(...$paths);
    $mean = Average::mean($flattened);

    expect($mean)->toBeGreaterThan(0.3)
        ->and($mean)->toBeLessThan(0.7);
});
