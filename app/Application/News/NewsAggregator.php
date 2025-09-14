<?php

namespace App\Application\News;

use App\Services\News\NewsProvider;

// Stats-only aggregator: consumes providers that implement fetchStat()

final class NewsAggregator
{
    public function __construct(public NewsProvider $provider) {}

    /**
     * Summarise sentiment for a pair for a given date.
     *
     * @return array{
     *   direction: string,
     *   strength: float,
     *   counts: array,
     * }
     */
    public function summary(string $pair, string $date = 'today', bool $fresh = false): array
    {
        // Stats-only mode: call fetchStat and derive direction/strength/counts
        if (! method_exists($this->provider, 'fetchStat')) {
            // Provider doesn't support stats: return neutral summary
            return [
                'direction' => 'neutral',
                'strength' => 0.0,
                'counts' => ['pos' => 0, 'neg' => 0, 'neu' => 0],
            ];
        }

        try {
            // Provider.fetchStat signature no longer accepts a freshness boolean.
            $s = $this->provider->fetchStat($pair, $date);
        } catch (\Throwable $e) {
            $s = [];
        }

        if (! is_array($s) || empty($s)) {
            return [
                'direction' => 'neutral',
                'strength' => 0.0,
                'counts' => ['pos' => 0, 'neg' => 0, 'neu' => 0],
            ];
        }

        $score = isset($s['score']) && is_numeric($s['score']) ? (float) $s['score'] : 0.0;
        $norm = max(-1.0, min(1.0, $score / 1.5));

        if ($norm > 0.15) {
            $direction = 'buy';
        } elseif ($norm < -0.15) {
            $direction = 'sell';
        } else {
            $direction = 'neutral';
        }

        $pos = max(0, (int) ($s['pos'] ?? 0));
        $neg = max(0, (int) ($s['neg'] ?? 0));
        $neu = max(0, (int) ($s['neu'] ?? 0));

        // Simplified strength: scale only from the provider's normalized score
        $strength = max(0.0, min(1.0, round(abs($norm), 3)));

        return [
            'direction' => $direction,
            'strength' => $strength,
            // Keep provider-level counts
            'counts' => ['pos' => $pos, 'neg' => $neg, 'neu' => $neu],
            // Add provenance fields requested by the context: raw provider score and the date param used
            'raw_score' => $score,
            'date' => $date,
        ];
    }
}
