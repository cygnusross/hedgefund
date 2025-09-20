<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use App\Domain\Rules\ResolvedRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class CandidateGenerator
{
    /**
     * Generate candidate parameter sets using 3-stage process:
     * Stage 1: Coarse grid (configurable via calibration.budgets.stage1_count)
     * Stage 2: Refinement around top candidates (configurable via calibration.budgets.stage2_count)
     * Stage 3: Final set for Monte Carlo evaluation (configurable via calibration.budgets.top_n_mc)
     */
    public function generate(CalibrationConfig $config, ResolvedRules $baseline, CalibrationDataset $dataset): Collection
    {
        Log::info('candidate_generation_started', [
            'tag' => $config->tag,
            'baseline_checksum' => md5(json_encode($baseline->base)),
        ]);

        // Stage 1: Generate coarse grid
        $stage1Candidates = $this->generateCoarseGrid($config, $baseline, $dataset);

        $targetStage1 = config('calibration.budgets.stage1_count', 300);
        Log::info('candidate_generation_stage1_completed', [
            'count' => $stage1Candidates->count(),
            'target' => $targetStage1,
        ]);

        return $stage1Candidates;
    }

    /**
     * Stage 1: Generate comprehensive coarse grid (size from config)
     */
    private function generateCoarseGrid(CalibrationConfig $config, ResolvedRules $baseline, CalibrationDataset $dataset): Collection
    {
        $candidates = collect();
        $base = $baseline->base;
        $marketOverrides = $baseline->marketOverrides;

        // Always include baseline
        $candidates->push(new CalibrationCandidate(
            id: $config->tag.'-baseline',
            baseRules: $base,
            marketOverrides: $marketOverrides,
            metadata: [
                'note' => 'baseline ruleset',
                'stage' => 'baseline',
            ],
        ));

        // Extract current parameter values for grid search
        $adxBase = (int) ($base['gates']['adx_min'] ?? 24);
        $rrBase = (float) ($base['execution']['rr'] ?? 2.0);
        $slMultBase = (float) ($base['execution']['sl_atr_mult'] ?? 2.0);
        $tpMultBase = (float) ($base['execution']['tp_atr_mult'] ?? 4.0);
        $riskBase = (float) ($base['risk']['per_trade_pct']['default'] ?? 1.0);
        $sentimentMode = $base['gates']['sentiment']['mode'] ?? 'contrarian';

        // Define parameter grids with constraints
        $parameterGrids = [
            'adx_min' => $this->generateRange($adxBase, 18, 35, 3), // ADX threshold
            'rr' => $this->generateRange($rrBase, 1.5, 3.0, 0.25), // Risk/reward ratio
            'sl_atr_mult' => $this->generateRange($slMultBase, 1.5, 3.0, 0.25), // Stop loss multiplier
            'tp_atr_mult' => $this->generateRange($tpMultBase, 2.0, 5.0, 0.5), // Take profit multiplier
            'risk_pct' => $this->generateRange($riskBase, 0.5, 2.0, 0.25), // Risk per trade
            'sentiment_mode' => ['contrarian', 'confirming', 'neutral'],
        ];

        // Generate parameter combinations with constraints
        $targetStage1 = config('calibration.budgets.stage1_count', 300);
        $maxCombinations = max(1, $targetStage1 - 1); // Subtract 1 for baseline
        $combinations = $this->generateParameterCombinations($parameterGrids, $maxCombinations);

        foreach ($combinations as $combo) {
            $mutated = $base;
            $mutated['gates']['adx_min'] = $combo['adx_min'];
            $mutated['gates']['sentiment']['mode'] = $combo['sentiment_mode'];
            $mutated['execution']['rr'] = $combo['rr'];
            $mutated['execution']['sl_atr_mult'] = $combo['sl_atr_mult'];
            $mutated['execution']['tp_atr_mult'] = $combo['tp_atr_mult'];
            $mutated['risk']['per_trade_pct']['default'] = $combo['risk_pct'];

            // Ensure TP multiplier is consistent with RR
            $expectedTpMult = $combo['sl_atr_mult'] * $combo['rr'];
            if (abs($combo['tp_atr_mult'] - $expectedTpMult) > 0.1) {
                $mutated['execution']['tp_atr_mult'] = $expectedTpMult;
            }

            $id = sprintf('%s-grid-%d', $config->tag, count($candidates)); // Exclude baseline from count

            $candidates->push(new CalibrationCandidate(
                id: $id,
                baseRules: $mutated,
                marketOverrides: $marketOverrides,
                metadata: [
                    'note' => 'coarse grid candidate',
                    'stage' => 'grid',
                    'adx_min' => $combo['adx_min'],
                    'sentiment' => $combo['sentiment_mode'],
                    'rr' => $combo['rr'],
                    'sl_atr_mult' => $combo['sl_atr_mult'],
                    'tp_atr_mult' => $mutated['execution']['tp_atr_mult'],
                    'risk_pct' => $combo['risk_pct'],
                ],
            ));
        }

        Log::info('candidate_generation_coarse_grid_completed', [
            'generated_combinations' => count($combinations),
            'total_candidates' => $candidates->count(),
        ]);

        return $candidates;
    }

    /**
     * Stage 2: Refine around top performers (±1 step sampling)
     * Called by CalibrationPipeline after initial scoring
     */
    public function refineTopCandidates(Collection $topCandidates, CalibrationConfig $config, ResolvedRules $baseline): Collection
    {
        $refined = collect();

        foreach ($topCandidates->take(20) as $candidateScore) {
            // Extract the CalibrationCandidate from CandidateScore
            $candidate = $candidateScore instanceof CandidateScore
                ? $candidateScore->candidate
                : $candidateScore;

            $refinements = $this->generateRefinements($candidate, $config, $baseline);
            $refined = $refined->merge($refinements);
        }

        Log::info('candidate_generation_stage2_completed', [
            'input_count' => $topCandidates->count(),
            'refined_count' => $refined->count(),
        ]);

        return $refined;
    }

    /**
     * Generate refinements around a successful candidate (±1 step)
     */
    private function generateRefinements(CalibrationCandidate $candidate, CalibrationConfig $config, ResolvedRules $baseline): Collection
    {
        $refinements = collect();
        $base = $candidate->baseRules;

        // Extract current values
        $adx = (int) ($base['gates']['adx_min'] ?? 24);
        $rr = (float) ($base['execution']['rr'] ?? 2.0);
        $slMult = (float) ($base['execution']['sl_atr_mult'] ?? 2.0);
        $risk = (float) ($base['risk']['per_trade_pct']['default'] ?? 1.0);

        // Generate small variations (±1 step)
        $variations = [
            ['adx_min' => $adx - 1],
            ['adx_min' => $adx + 1],
            ['rr' => round($rr - 0.25, 2)],
            ['rr' => round($rr + 0.25, 2)],
            ['sl_atr_mult' => round($slMult - 0.25, 2)],
            ['sl_atr_mult' => round($slMult + 0.25, 2)],
            ['risk_pct' => round($risk - 0.25, 2)],
            ['risk_pct' => round($risk + 0.25, 2)],
        ];

        foreach ($variations as $idx => $variation) {
            $mutated = $base;

            // Ensure expected keys exist to avoid undefined index errors later
            if (! isset($mutated['execution']) || ! is_array($mutated['execution'])) {
                $mutated['execution'] = [];
            }
            if (! array_key_exists('sl_atr_mult', $mutated['execution'])) {
                $mutated['execution']['sl_atr_mult'] = $slMult;
            }
            if (! array_key_exists('tp_atr_mult', $mutated['execution'])) {
                $mutated['execution']['tp_atr_mult'] = ($mutated['execution']['sl_atr_mult'] ?? $slMult) * $rr;
            }
            if (! isset($mutated['risk']) || ! is_array($mutated['risk'])) {
                $mutated['risk'] = ['per_trade_pct' => ['default' => $risk]];
            }
            if (! isset($mutated['risk']['per_trade_pct']['default'])) {
                $mutated['risk']['per_trade_pct']['default'] = $risk;
            }

            if (isset($variation['adx_min'])) {
                $mutated['gates']['adx_min'] = $this->clamp($variation['adx_min'], 18, 35);
            }
            if (isset($variation['rr'])) {
                $newRr = $this->clampFloat($variation['rr'], 1.5, 3.0);
                $mutated['execution']['rr'] = $newRr;
                $mutated['execution']['tp_atr_mult'] = $slMult * $newRr; // Maintain consistency
            }
            if (isset($variation['sl_atr_mult'])) {
                $newSlMult = $this->clampFloat($variation['sl_atr_mult'], 1.5, 3.0);
                $mutated['execution']['sl_atr_mult'] = $newSlMult;
                $mutated['execution']['tp_atr_mult'] = $newSlMult * $rr; // Maintain consistency
            }
            if (isset($variation['risk_pct'])) {
                $mutated['risk']['per_trade_pct']['default'] = $this->clampFloat($variation['risk_pct'], 0.5, 2.0);
            }

            // Validate refined parameters
            $newRr = $mutated['execution']['rr'];
            $newSlMult = $mutated['execution']['sl_atr_mult'];
            $newTpMult = $mutated['execution']['tp_atr_mult'];
            $newRisk = $mutated['risk']['per_trade_pct']['default'];

            if (! $this->areParametersValid($newRr, $newSlMult, $newTpMult, $newRisk)) {
                continue;
            }

            $id = sprintf('%s-refine-%s-%d', $config->tag, substr($candidate->id, -8), $idx);
            $refinements->push(new CalibrationCandidate(
                id: $id,
                baseRules: $mutated,
                marketOverrides: $candidate->marketOverrides,
                metadata: [
                    'note' => 'refined candidate',
                    'stage' => 'refined',
                    'parent_id' => $candidate->id,
                    'variation' => $variation,
                ],
            ));
        }

        return $refinements;
    }

    /**
     * Validate parameter combinations for feasibility
     */
    private function areParametersValid(float $rr, float $slMult, float $tpMult, float $risk): bool
    {
        // RR consistency: TP multiplier should roughly equal SL multiplier * RR
        if (abs($tpMult - ($slMult * $rr)) > 0.5) {
            return false;
        }

        // Risk bounds
        if ($risk < 0.5 || $risk > 2.0) {
            return false;
        }

        // Minimum viable RR
        if ($rr < 1.25) {
            return false;
        }

        // Broker stop constraints (minimum stop distance)
        if ($slMult < 1.5) {
            return false;
        }

        return true;
    }

    /**
     * Generate range of values around a base value
     */
    private function generateRange(float $base, float $min, float $max, float $step): array
    {
        $range = [];

        // Include the base value
        if ($base >= $min && $base <= $max) {
            $range[] = $base;
        }

        // Generate values below base
        $current = $base - $step;
        while ($current >= $min) {
            $range[] = round($current, 2);
            $current -= $step;
        }

        // Generate values above base
        $current = $base + $step;
        while ($current <= $max) {
            $range[] = round($current, 2);
            $current += $step;
        }

        return array_unique($range);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        return round(max($min, min($max, $value)), 2);
    }

    /**
     * Generate parameter combinations using stratified sampling to ensure good coverage
     */
    private function generateParameterCombinations(array $parameterGrids, int $maxCombinations): array
    {
        $adxValues = $parameterGrids['adx_min'];
        $sentimentValues = $parameterGrids['sentiment_mode'];
        $rrValues = $parameterGrids['rr'];
        $slValues = $parameterGrids['sl_atr_mult'];
        $tpValues = $parameterGrids['tp_atr_mult'];
        $riskValues = $parameterGrids['risk_pct'];

        // Calculate total possible combinations
        $totalPossible = count($adxValues) * count($sentimentValues) * count($rrValues) *
            count($slValues) * count($tpValues) * count($riskValues);

        // If we can fit all combinations, generate them all
        if ($totalPossible <= $maxCombinations) {
            return $this->generateAllCombinations($parameterGrids);
        }

        // Otherwise use stratified sampling to ensure good coverage across all dimensions
        return $this->generateStratifiedSample($parameterGrids, $maxCombinations);
    }

    /**
     * Generate all possible combinations (when total is manageable)
     */
    private function generateAllCombinations(array $parameterGrids): array
    {
        $combinations = [];
        $adxValues = $parameterGrids['adx_min'];
        $sentimentValues = $parameterGrids['sentiment_mode'];
        $rrValues = $parameterGrids['rr'];
        $slValues = $parameterGrids['sl_atr_mult'];
        $tpValues = $parameterGrids['tp_atr_mult'];
        $riskValues = $parameterGrids['risk_pct'];

        foreach ($adxValues as $adx) {
            foreach ($sentimentValues as $sentiment) {
                foreach ($rrValues as $rr) {
                    foreach ($slValues as $slMult) {
                        foreach ($tpValues as $tpMult) {
                            foreach ($riskValues as $riskPct) {
                                if (! $this->areParametersValid($rr, $slMult, $tpMult, $riskPct)) {
                                    continue;
                                }

                                $combinations[] = [
                                    'adx_min' => $adx,
                                    'sentiment_mode' => $sentiment,
                                    'rr' => $rr,
                                    'sl_atr_mult' => $slMult,
                                    'tp_atr_mult' => $tpMult,
                                    'risk_pct' => $riskPct,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $combinations;
    }

    /**
     * Generate stratified sample to ensure good coverage across all parameter dimensions
     */
    private function generateStratifiedSample(array $parameterGrids, int $maxCombinations): array
    {
        $adxValues = $parameterGrids['adx_min'];
        $sentimentValues = $parameterGrids['sentiment_mode'];
        $rrValues = $parameterGrids['rr'];
        $slValues = $parameterGrids['sl_atr_mult'];
        $tpValues = $parameterGrids['tp_atr_mult'];
        $riskValues = $parameterGrids['risk_pct'];

        $combinations = [];

        // Aim for at least 2-3 examples of each outer parameter value
        $targetOuterCombinations = min(30, $maxCombinations / 10); // Use about 1/10th for outer variation
        $outerCombinations = count($adxValues) * count($sentimentValues) * count($rrValues);
        $outerStep = max(1, floor($outerCombinations / $targetOuterCombinations));

        // For inner loops, limit combinations to fit within remaining budget
        $innerBudget = floor($maxCombinations / $targetOuterCombinations);
        $innerCombinations = count($slValues) * count($tpValues) * count($riskValues);
        $innerStep = max(1, floor($innerCombinations / $innerBudget));

        $outerCount = 0;
        $usedOuterCombos = 0;
        foreach ($adxValues as $adx) {
            foreach ($sentimentValues as $sentiment) {
                foreach ($rrValues as $rr) {
                    // Use stratified sampling for outer combinations
                    if ($outerCount % $outerStep != 0) {
                        $outerCount++;

                        continue;
                    }

                    $innerCount = 0;
                    foreach ($slValues as $slMult) {
                        foreach ($tpValues as $tpMult) {
                            foreach ($riskValues as $riskPct) {
                                // Use stratified sampling for inner combinations too
                                if ($innerCount % $innerStep != 0) {
                                    $innerCount++;

                                    continue;
                                }

                                if (count($combinations) >= $maxCombinations) {
                                    return $combinations;
                                }

                                if (! $this->areParametersValid($rr, $slMult, $tpMult, $riskPct)) {
                                    $innerCount++;

                                    continue;
                                }

                                $combinations[] = [
                                    'adx_min' => $adx,
                                    'sentiment_mode' => $sentiment,
                                    'rr' => $rr,
                                    'sl_atr_mult' => $slMult,
                                    'tp_atr_mult' => $tpMult,
                                    'risk_pct' => $riskPct,
                                ];

                                $innerCount++;
                            }
                        }
                    }
                    $outerCount++;
                    $usedOuterCombos++;

                    if ($usedOuterCombos >= $targetOuterCombinations) {
                        break 3; // Break out of all three outer loops
                    }
                }
            }
        }

        return $combinations;
    }
}
