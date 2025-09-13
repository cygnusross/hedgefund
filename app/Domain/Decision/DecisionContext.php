<?php

namespace App\Domain\Decision;

use App\Domain\Market\FeatureSet;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

/**
 * Immutable context passed to decision logic.
 */
final class DecisionContext
{
    public function __construct(
        public readonly string $pair,
        public readonly \DateTimeImmutable $ts,
        public readonly FeatureSet $features,
        // Optional meta fields (provenance, freshness) passed from ContextBuilder
        public readonly array $meta = [],
    ) {
        // Intentionally immutable; no side-effects.
    }

    /**
     * Return a concise array representation including flattened features.
     * Context-specific fields like 'news', 'calendar', and 'blackout' are added by callers (e.g. ContextBuilder).
     */
    public function toArray(): array
    {
        return [
            'pair' => $this->pair,
            'ts' => $this->ts->format(DATE_ATOM),
            'features' => [
                'ema20' => $this->features->ema20,
                'ema20_z' => $this->features->ema20_z,
                'atr5m' => $this->features->atr5m,
                'adx5m' => $this->features->adx5m,
                'recentRangePips' => $this->features->recentRangePips,
                'trend30m' => $this->features->trend30m,
                'supportLevels' => $this->features->supportLevels,
                'resistanceLevels' => $this->features->resistanceLevels,
            ],
            // Add meta block for provenance and freshness information; empty when not provided
            'meta' => $this->meta,
        ];
    }
}
