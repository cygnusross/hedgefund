<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Decision\DTO\ContextSnapshot;
use App\Domain\Decision\DTO\IgRulesSnapshot;
use App\Domain\Decision\DTO\MarketSnapshot;
use App\Domain\Decision\DTO\RulesSnapshot;
use App\Domain\Decision\DTO\SentimentSnapshot;
use App\Domain\Market\FeatureSet;

final readonly class FeatureExtractor
{
    public function __construct(private ContextSnapshot $context) {}

    public function features(): FeatureSet
    {
        return $this->context->features();
    }

    public function market(): MarketSnapshot
    {
        return $this->context->market();
    }

    public function meta(): \App\Domain\Decision\DTO\MetaSnapshot
    {
        return $this->context->meta();
    }

    public function calendar(): \App\Domain\Decision\DTO\CalendarSnapshot
    {
        return $this->context->calendar();
    }

    public function getRsi(): ?float
    {
        return $this->features()->rsi14;
    }

    public function getStochasticK(): ?float
    {
        return $this->features()->stoch_k;
    }

    public function getStochasticD(): ?float
    {
        return $this->features()->stoch_d;
    }

    public function getWilliamsR(): ?float
    {
        return $this->features()->williamsR;
    }

    public function getCci(): ?float
    {
        return $this->features()->cci;
    }

    public function getParabolicSAR(): ?float
    {
        return $this->features()->parabolicSAR;
    }

    public function getParabolicSARTrend(): ?string
    {
        return $this->features()->parabolicSARTrend;
    }

    public function getBollingerBandUpper(): ?float
    {
        return $this->features()->bb_upper;
    }

    public function getBollingerBandLower(): ?float
    {
        return $this->features()->bb_lower;
    }

    public function getTrueRangeUpper(): ?float
    {
        return $this->features()->tr_upper;
    }

    public function getTrueRangeLower(): ?float
    {
        return $this->features()->tr_lower;
    }

    public function getAdx(): ?float
    {
        return $this->features()->adx5m;
    }

    public function getEmaZ(): ?float
    {
        return $this->features()->ema20_z;
    }

    public function getLastPrice(): ?float
    {
        return $this->market()->lastPrice();
    }

    public function getSpread(): ?float
    {
        return $this->market()->spreadEstimatePips();
    }

    public function getMarketStatus(): ?string
    {
        return $this->market()->status();
    }

    public function getDataAge(): ?int
    {
        return $this->meta()->dataAgeSec();
    }

    public function getPairNorm(): string
    {
        return $this->meta()->pairNorm();
    }

    public function getSleeveBalance(): float
    {
        return $this->meta()->sleeveBalance();
    }

    public function isWithinBlackout(): bool
    {
        return $this->context->isWithinBlackout();
    }

    public function getSentiment(): ?SentimentSnapshot
    {
        return $this->market()->sentiment();
    }

    public function getIgRules(): ?IgRulesSnapshot
    {
        return $this->market()->igRules();
    }

    public function getRules(): ?RulesSnapshot
    {
        return $this->context->rules();
    }

    public function getGateOverride(string $key): ?float
    {
        return $this->market()->gateOverride($key);
    }
}
