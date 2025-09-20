<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

final class CandidateScore
{
    /**
     * @param  array<string, float>  $metrics
     * @param  array<string, float>  $riskMetrics
     */
    public function __construct(
        public readonly CalibrationCandidate $candidate,
        public readonly array $metrics,
        public readonly array $riskMetrics = [],
    ) {}

    public function composite(): float
    {
        return $this->metrics['composite'] ?? 0.0;
    }
}
