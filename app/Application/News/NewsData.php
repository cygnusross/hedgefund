<?php

namespace App\Application\News;

readonly class NewsData
{
    public function __construct(
        public float $rawScore,
        public float $strength,
        public array $counts,
        public string $pair,
        public string $date,
        public string $source = 'forexnewsapi',
    ) {}

    /**
     * Create NewsData from database payload structure
     */
    public static function fromPayload(array $payload, string $pair, string $date): self
    {
        return new self(
            rawScore: (float) ($payload['raw_score'] ?? 0.0),
            strength: (float) ($payload['strength'] ?? 0.0),
            counts: $payload['counts'] ?? ['pos' => 0, 'neg' => 0, 'neu' => 0],
            pair: $payload['pair'] ?? $pair,
            date: $payload['date'] ?? $date,
            source: $payload['source'] ?? 'forexnewsapi',
        );
    }

    /**
     * Create NewsData from NewsStat model
     */
    public static function fromModel(\App\Models\NewsStat $newsStat): self
    {
        // If payload exists, use it; otherwise construct from model attributes
        if ($newsStat->payload) {
            return self::fromPayload(
                $newsStat->payload,
                $newsStat->pair_norm,
                $newsStat->stat_date
            );
        }

        return new self(
            rawScore: (float) $newsStat->raw_score,
            strength: (float) $newsStat->strength,
            counts: [
                'pos' => (int) $newsStat->pos,
                'neg' => (int) $newsStat->neg,
                'neu' => (int) $newsStat->neu,
            ],
            pair: $newsStat->pair_norm,
            date: $newsStat->stat_date->toDateString(), // Convert Carbon to date string
            source: $newsStat->source,
        );
    }

    /**
     * Create neutral NewsData for when no data is available
     */
    public static function neutral(string $pair, string $date): self
    {
        return new self(
            rawScore: 0.0,
            strength: 0.0,
            counts: ['pos' => 0, 'neg' => 0, 'neu' => 0],
            pair: $pair,
            date: $date,
        );
    }
}
