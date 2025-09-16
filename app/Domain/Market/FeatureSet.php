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
        // New momentum indicators
        public readonly ?float $rsi14 = null,
        // MACD components
        public readonly ?float $macd_line = null,
        public readonly ?float $macd_signal = null,
        public readonly ?float $macd_histogram = null,
        // Bollinger Bands
        public readonly ?float $bb_upper = null,
        public readonly ?float $bb_middle = null,
        public readonly ?float $bb_lower = null,
        // Stochastic Oscillator
        public readonly ?float $stoch_k = null,
        public readonly ?float $stoch_d = null,
        // Williams %R
        public readonly ?float $williamsR = null,
        // Commodity Channel Index
        public readonly ?float $cci = null,
        // Parabolic SAR
        public readonly ?float $parabolicSAR = null,
        public readonly ?string $parabolicSARTrend = null,
        // True Range Bands
        public readonly ?float $tr_upper = null,
        public readonly ?float $tr_middle = null,
        public readonly ?float $tr_lower = null,
    ) {}
}
