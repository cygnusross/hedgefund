<?php

namespace App\Domain\Rules;

use App\Models\RuleSet;

final class ResolvedRules
{
    public function __construct(
        public readonly array $base,
        public readonly array $marketOverrides,
        public readonly array $emergencyOverrides,
        public readonly array $metadata,
        public readonly ?string $tag = null,
    ) {}

    public static function fromModel(RuleSet $model): self
    {
        return new self(
            base: $model->base_rules ?? [],
            marketOverrides: $model->market_overrides ?? [],
            emergencyOverrides: $model->emergency_overrides ?? [],
            metadata: [
                'tag' => $model->tag,
                'period_start' => optional($model->period_start)->toDateString(),
                'period_end' => optional($model->period_end)->toDateString(),
                'metrics' => $model->metrics,
                'risk_bands' => $model->risk_bands,
                'regime_snapshot' => $model->regime_snapshot,
                'provenance' => $model->provenance,
                'model_artifacts' => $model->model_artifacts,
                'feature_hash' => $model->feature_hash,
                'mc_seed' => $model->mc_seed,
                'created_at' => optional($model->created_at)->toDateTimeString(),
            ],
            tag: $model->tag,
        );
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode([
            'base' => $this->base,
            'market_overrides' => $this->marketOverrides,
            'emergency_overrides' => $this->emergencyOverrides,
            'metadata' => $this->metadata,
        ], JSON_THROW_ON_ERROR));
    }

    public function toCachePayload(): array
    {
        return [
            'base' => $this->base,
            'market_overrides' => $this->marketOverrides,
            'emergency_overrides' => $this->emergencyOverrides,
            'metadata' => $this->metadata,
            'checksum' => $this->checksum(),
        ];
    }
}
