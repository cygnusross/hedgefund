<?php

namespace App\Domain\Market;

final class FeatureSet
{
    public function __construct(
        public readonly \DateTimeImmutable $ts,
        public readonly float $ema20,
        public readonly float $atr5m,
        public readonly float $ema20_z,
        public readonly float $recentRangePips,
        public readonly float $adx5m,
        public readonly string $trend30m,
        public readonly array $supportLevels,
        public readonly array $resistanceLevels,
    ) {}
}
