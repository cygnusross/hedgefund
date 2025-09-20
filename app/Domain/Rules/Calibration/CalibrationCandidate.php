<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

final class CalibrationCandidate
{
    /**
     * @param  array<string, mixed>  $baseRules
     * @param  array<string, mixed>  $marketOverrides
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $baseRules,
        public readonly array $marketOverrides,
        public readonly array $metadata = [],
    ) {}
}
