<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use Ds\Vector;
use MathPHP\Probability\Distribution\Continuous\Continuous;
use MathPHP\Probability\Distribution\Continuous\Normal;

final class MonteCarloSimulator
{
    /**
     * Simulate random paths from a provided continuous distribution.
     *
     * @return array<int, array<int, float>>
     */
    public function simulate(Continuous $distribution, int $paths, int $steps): array
    {
        if ($paths <= 0 || $steps <= 0) {
            return [];
        }

        $series = new Vector();
        for ($i = 0; $i < $paths; $i++) {
            $path = new Vector();
            for ($j = 0; $j < $steps; $j++) {
                $path->push((float) $distribution->rand());
            }
            $series->push($path);
        }

        return $series->map(static fn (Vector $path) => $path->toArray())->toArray();
    }

    /**
     * Convenience wrapper for Gaussian paths.
     *
     * @return array<int, array<int, float>>
     */
    public function simulateNormal(int $paths, int $steps, float $mean = 0.0, float $stdev = 1.0): array
    {
        if ($stdev <= 0.0) {
            return [];
        }

        return $this->simulate(new Normal($mean, $stdev), $paths, $steps);
    }
}
