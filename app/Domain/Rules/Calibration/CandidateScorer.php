<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class CandidateScorer
{
    public function __construct(
        private readonly RubixMLPipeline $mlPipeline
    ) {}

    public function score(Collection $candidates, CalibrationDataset $dataset): Collection
    {
        // Prepare the ML model if not already ready
        try {
            $this->mlPipeline->prepareModel($dataset);
        } catch (\Throwable $e) {
            Log::warning('rubix_ml_preparation_failed', [
                'error' => $e->getMessage(),
                'falling_back_to_heuristic' => true,
            ]);

            return $this->fallbackHeuristicScoring($candidates, $dataset);
        }

        $costBase = $dataset->costEstimates !== []
            ? array_sum($dataset->costEstimates) / count($dataset->costEstimates)
            : 0.04;

        Log::info('candidate_scoring_started', [
            'candidate_count' => $candidates->count(),
            'cost_base' => $costBase,
        ]);

        return $candidates->map(function (CalibrationCandidate $candidate) use ($costBase, $dataset) {
            try {
                // Use Rubix ML to predict profitability
                $mlScore = $this->mlPipeline->scoreCandidate($candidate, $dataset);

                // Drop candidates with insufficient trade frequency
                if ($mlScore < -900) {
                    Log::debug('candidate_dropped_ml_score', [
                        'candidate_id' => $candidate->id,
                        'ml_score' => $mlScore,
                    ]);

                    return null; // Filter out later
                }

                // Convert ML score to traditional metrics for consistency
                $hitRate = $this->estimateHitRateFromMLScore($mlScore);
                $tradesPerDay = $this->estimateTradeFrequency($candidate, $dataset);
                $expectancy = max(-2.0, $mlScore - $costBase); // Subtract cost base
                $sharpe = $expectancy > 0 ? round($expectancy / max(0.2, $costBase + 0.1), 3) : 0.1;

                $composite = max(0.0, ($hitRate * 0.3) + ($expectancy * 0.5) + ($sharpe * 0.2));

                return new CandidateScore(
                    candidate: $candidate,
                    metrics: [
                        'hit_rate' => $hitRate,
                        'trades_per_day' => $tradesPerDay,
                        'expectancy' => $expectancy,
                        'sharpe_proxy' => $sharpe,
                        'composite' => $composite,
                        'ml_score' => $mlScore,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('candidate_scoring_failed', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);

                // Fallback to simple heuristic for this candidate
                return $this->scoreHeuristically($candidate, $dataset, $costBase);
            }
        })
            ->filter() // Remove null entries (dropped candidates)
            ->sortByDesc(fn (CandidateScore $score) => $score->composite())
            ->values();
    }

    /**
     * Fallback heuristic scoring when ML is unavailable
     */
    private function fallbackHeuristicScoring(Collection $candidates, CalibrationDataset $dataset): Collection
    {
        $costBase = $dataset->costEstimates !== []
            ? array_sum($dataset->costEstimates) / count($dataset->costEstimates)
            : 0.04;

        return $candidates->map(function (CalibrationCandidate $candidate) use ($costBase, $dataset) {
            return $this->scoreHeuristically($candidate, $dataset, $costBase);
        })->sortByDesc(fn (CandidateScore $score) => $score->composite())->values();
    }

    /**
     * Simple heuristic scoring for individual candidates
     */
    private function scoreHeuristically(CalibrationCandidate $candidate, CalibrationDataset $dataset, float $costBase): CandidateScore
    {
        // Use candidate ID for deterministic pseudo-random scoring
        $seed = crc32($candidate->id.$dataset->tag);
        mt_srand($seed);

        // Apply some basic heuristics based on rule parameters
        $rules = $candidate->baseRules;
        $adxMin = $rules['gates']['adx_min'] ?? 24;
        $rr = $rules['execution']['rr'] ?? 2.0;
        $sentimentMode = $rules['gates']['sentiment']['mode'] ?? 'contrarian';

        // Adjust base probabilities based on parameters
        $baseHitRate = 60; // Base 60%
        $baseHitRate += ($adxMin > 25) ? 3 : -2; // Higher ADX threshold
        $baseHitRate += ($rr > 2.0) ? -2 : 1; // Higher RR requires more precision
        $baseHitRate += ($sentimentMode === 'contrarian') ? 2 : -1; // Contrarian slightly better

        $hitRate = round(max(40, min(75, mt_rand($baseHitRate - 5, $baseHitRate + 5))) / 100, 3);
        $tradesPerDay = round(max(0.3, mt_rand(30, 120) / 100), 2);
        $expectancy = round($hitRate * ($rr * 0.9) - (1 - $hitRate) * 1.0 - $costBase, 3);
        $sharpe = round(max(0.1, $expectancy / max(0.2, $costBase + 0.1)), 3);

        $composite = round(max(0.0, ($hitRate * 0.4) + ($expectancy * 0.4) + ($sharpe * 0.2)), 4);

        return new CandidateScore(
            candidate: $candidate,
            metrics: [
                'hit_rate' => $hitRate,
                'trades_per_day' => $tradesPerDay,
                'expectancy' => $expectancy,
                'sharpe_proxy' => $sharpe,
                'composite' => $composite,
                'scoring_method' => 'heuristic',
            ],
        );
    }

    /**
     * Convert ML expectancy score to estimated hit rate
     */
    private function estimateHitRateFromMLScore(float $mlScore): float
    {
        // Reverse engineer hit rate from expectancy
        // Assuming 2R avg win, 1R avg loss: expectancy = hitRate * 2 - (1-hitRate) * 1
        // Solving for hitRate: hitRate = (expectancy + 1) / 3
        $estimatedHitRate = ($mlScore + 1.0) / 3.0;

        return round(max(0.35, min(0.75, $estimatedHitRate)), 3);
    }

    /**
     * Estimate trade frequency based on rule parameters
     */
    private function estimateTradeFrequency(CalibrationCandidate $candidate, CalibrationDataset $dataset): float
    {
        $rules = $candidate->baseRules;
        $adxMin = $rules['gates']['adx_min'] ?? 24;
        $sentimentMode = $rules['gates']['sentiment']['mode'] ?? 'contrarian';

        // Lower ADX threshold = more trades
        $baseFrequency = 1.0;
        $baseFrequency *= ($adxMin > 25) ? 0.8 : 1.2;
        $baseFrequency *= ($sentimentMode === 'neutral') ? 1.3 : 1.0;

        return round(max(0.3, min(2.0, $baseFrequency)), 2);
    }
}
