<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Decision\Contracts\DecisionContextContract;
use App\Domain\Decision\DTO\DecisionMetadata;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Market\FeatureSet;

final class DecisionContext implements DecisionContextContract
{
    private readonly DecisionMetadata $meta;

    public function __construct(
        private readonly string $pair,
        private readonly \DateTimeImmutable $ts,
        private readonly FeatureSet $features,
        ?DecisionMetadata $meta = null,
    ) {
        $this->meta = $meta ?? new DecisionMetadata;
    }

    public function pair(): string
    {
        return $this->pair;
    }

    public function timestamp(): \DateTimeImmutable
    {
        return $this->ts;
    }

    public function features(): FeatureSet
    {
        return $this->features;
    }

    public function meta(): DecisionMetadata
    {
        return $this->meta;
    }

    public function toRequest(): DecisionRequest
    {
        return DecisionRequest::fromArray($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'pair' => $this->pair,
            'ts' => $this->ts->format(DATE_ATOM),
            'features' => $this->featuresToArray($this->features),
            'meta' => $this->meta->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function featuresToArray(FeatureSet $features): array
    {
        return [
            'ema20' => $features->ema20,
            'ema20_z' => $features->ema20_z,
            'atr5m' => $features->atr5m,
            'adx5m' => $features->adx5m,
            'recentRangePips' => $features->recentRangePips,
            'trend30m' => $features->trend30m,
            'supportLevels' => $features->supportLevels,
            'resistanceLevels' => $features->resistanceLevels,
            'rsi14' => $features->rsi14,
            'macd_line' => $features->macd_line,
            'macd_signal' => $features->macd_signal,
            'macd_histogram' => $features->macd_histogram,
            'bb_upper' => $features->bb_upper,
            'bb_middle' => $features->bb_middle,
            'bb_lower' => $features->bb_lower,
            'stoch_k' => $features->stoch_k,
            'stoch_d' => $features->stoch_d,
            'williamsR' => $features->williamsR,
            'cci' => $features->cci,
            'parabolicSAR' => $features->parabolicSAR,
            'parabolicSARTrend' => $features->parabolicSARTrend,
            'tr_upper' => $features->tr_upper,
            'tr_middle' => $features->tr_middle,
            'tr_lower' => $features->tr_lower,
        ];
    }
}
