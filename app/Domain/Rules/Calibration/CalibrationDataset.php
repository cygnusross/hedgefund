<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

final class CalibrationDataset
{
    /**
     * @param  array<int, string>  $markets
     * @param  array<string, array<string, mixed>>  $snapshots
     * @param  array<string, mixed>  $regimeSummary
     * @param  array<string, float>  $costEstimates
     */
    public function __construct(
        public readonly string $tag,
        public readonly array $markets,
        public readonly array $snapshots,
        public readonly array $regimeSummary,
        public readonly array $costEstimates,
    ) {}

    public function hasSnapshots(): bool
    {
        return $this->snapshots !== [];
    }
}
