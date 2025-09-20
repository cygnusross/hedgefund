<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Rules\AlphaRules;
use Illuminate\Support\Facades\Log;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Estimator;
use Rubix\ML\PersistentModel;
use Rubix\ML\Persisters\Filesystem;

final class RubixMLPipeline
{
    private ?Estimator $model = null;

    private static bool $globalModelPrepared = false;

    /**
     * Reset the global model preparation state (for testing)
     */
    public static function resetModelState(): void
    {
        self::$globalModelPrepared = false;
    }

    public function __construct(
        private readonly string $modelStoragePath = 'storage/app/ml_models/trade_classifier.rbx'
    ) {}

    /**
     * Train or load the profitability prediction model
     */
    public function prepareModel(CalibrationDataset $dataset): void
    {
        // Skip if already prepared in this process (global singleton caching)
        if (self::$globalModelPrepared) {
            return;
        }

        // In the testing environment we avoid expensive training and instead
        // create a minimal dummy model to keep tests fast and deterministic.
        if (app()->environment('testing') || config('calibration.mc_skip', false)) {
            if (! $this->modelExists()) {
                $this->createDummyModel();
            } else {
                $this->loadModel();
            }

            // Only log once per process in testing to reduce noise
            if (! self::$globalModelPrepared) {
                Log::info('rubix_ml_model_prepared_in_testing');
            }

            self::$globalModelPrepared = true;

            return;
        }

        if ($this->modelExists()) {
            $this->loadModel();
            Log::info('rubix_ml_model_loaded_from_disk');
        } else {
            $this->trainModel($dataset);
            Log::info('rubix_ml_model_trained_from_scratch');
        }

        self::$globalModelPrepared = true;
    }

    /**
     * Predict profitability for candidate rule sets
     */
    public function scoreCandidate(CalibrationCandidate $candidate, CalibrationDataset $dataset): float
    {
        if ($this->model === null) {
            throw new \RuntimeException('Model not prepared. Call prepareModel() first.');
        }

        // Generate features for this candidate
        $features = $this->generateCandidateFeatures($candidate, $dataset);

        if (empty($features)) {
            return 0.0; // No valid trades to predict
        }

        // Predict profitability probabilities
        $unlabeledDataset = new Unlabeled($features);
        $predictions = $this->model->predict($unlabeledDataset);

        // Calculate hit rate and expectancy
        $hitRate = $this->calculateHitRate($predictions);
        $expectancy = $this->calculateExpectancy($predictions);

        // Adjust for trade frequency (automatically drop <0.3 trades/day)
        $tradingDays = $this->estimateTradingDays($dataset);
        $tradeFrequency = count($features) / max($tradingDays, 1);
        if ($tradeFrequency < 0.3) {
            Log::debug('candidate_dropped_low_frequency', [
                'candidate_id' => $candidate->id,
                'trade_frequency' => $tradeFrequency,
            ]);

            return -999.0; // Flag for removal
        }

        // Combine metrics with trade frequency weighting
        $score = $expectancy * min(1.0, $tradeFrequency / 1.0); // Optimal frequency ~1 trade/day

        Log::debug('rubix_ml_candidate_scored', [
            'candidate_id' => $candidate->id,
            'hit_rate' => $hitRate,
            'expectancy' => $expectancy,
            'trade_frequency' => $tradeFrequency,
            'final_score' => $score,
        ]);

        return $score;
    }

    /**
     * Generate feature vectors for a candidate against historical data
     */
    private function generateCandidateFeatures(CalibrationCandidate $candidate, CalibrationDataset $dataset): array
    {
        $features = [];
        $rules = new AlphaRules;
        $reflection = new \ReflectionClass($rules);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setAccessible(true);
        $dataProperty->setValue($rules, $candidate->baseRules);

        $decisionEngine = new LiveDecisionEngine($rules);

        // Extract contexts from dataset snapshots
        $contexts = $this->extractContextsFromDataset($dataset);

        foreach ($contexts as $context) {
            try {
                $request = DecisionRequest::fromArray($context);
                $result = $decisionEngine->decide($request);

                if ($result->action() === 'hold' || $result->isBlocked()) {
                    continue; // Skip non-trading decisions
                }

                // Extract features for ML prediction
                $featureVector = $this->extractFeatures($context, $result->toArray(), $candidate);
                if ($featureVector !== null) {
                    $features[] = $featureVector;
                }
            } catch (\Throwable $e) {
                // Skip problematic contexts
                continue;
            }
        }

        return $features;
    }

    /**
     * Extract market contexts from calibration dataset
     */
    private function extractContextsFromDataset(CalibrationDataset $dataset): array
    {
        $contexts = [];

        // Convert dataset snapshots to decision contexts
        foreach ($dataset->snapshots as $market => $snapshots) {
            foreach ($snapshots as $timestamp => $data) {
                if (! is_array($data) || ! isset($data['features'], $data['market'])) {
                    continue;
                }

                $contexts[] = [
                    'meta' => [
                        'pair_norm' => $market,
                        'timestamp' => $timestamp,
                        'data_age_sec' => 60, // Assume reasonably fresh
                    ],
                    'market' => $data['market'],
                    'features' => $data['features'],
                    'calendar' => $data['calendar'] ?? ['within_blackout' => false],
                ];
            }
        }

        return $contexts;
    }

    /**
     * Extract ML features from market context and decision
     */
    private function extractFeatures(array $context, array $decision, CalibrationCandidate $candidate): ?array
    {
        $market = $context['market'] ?? [];
        $features = $context['features'] ?? [];

        // Core market features
        $atr = $market['atr5m_pips'] ?? 0;
        $spread = $market['spread_estimate_pips'] ?? 0;
        $lastPrice = $market['last_price'] ?? 0;

        // Technical features
        $adx = $features['adx5m'] ?? 0;
        $emaZ = $features['ema20_z'] ?? 0;
        $trend = $features['trend30m'] ?? 'flat';

        // Decision features
        $entry = $decision['entry'] ?? 0;
        $sl = $decision['sl'] ?? 0;
        $tp = $decision['tp'] ?? 0;
        $rr = abs($tp - $entry) / max(abs($entry - $sl), 0.0001);
        $riskPct = $decision['size_pct'] ?? 1.0;

        // Rule parameters as features
        $rules = $candidate->baseRules;
        $adxMin = $rules['gates']['adx_min'] ?? 24;
        $sentimentMode = $rules['gates']['sentiment']['mode'] ?? 'contrarian';
        $slMult = $rules['execution']['sl_atr_mult'] ?? 2.0;

        // Validate features
        if ($atr <= 0 || $lastPrice <= 0) {
            return null;
        }

        return [
            // Market condition features
            $atr,
            $spread,
            $spread / max($atr, 0.1), // Spread-to-ATR ratio
            $adx,
            $emaZ,
            $trend === 'up' ? 1 : ($trend === 'down' ? -1 : 0),

            // Risk/reward features
            $rr,
            $riskPct,
            $slMult,

            // Rule parameters
            $adxMin,
            $sentimentMode === 'contrarian' ? 1 : ($sentimentMode === 'confirming' ? -1 : 0),

            // Time features (hour of day affects performance)
            (int) date('H', strtotime($context['meta']['timestamp'] ?? 'now')),
        ];
    }

    /**
     * Train the model using historical trade outcomes
     */
    private function trainModel(CalibrationDataset $dataset): void
    {
        [$features, $labels] = $this->prepareTrainingData($dataset);

        if (empty($features)) {
            // Create a minimal dummy model for testing/development purposes
            Log::warning('rubix_ml_no_training_data', [
                'action' => 'creating_dummy_model',
                'message' => 'No training data available, creating minimal model for testing',
            ]);

            $this->createDummyModel();

            return;
        }

        $labeledDataset = new Labeled($features, $labels);

        // Use Random Forest for robustness
        $estimator = new RandomForest;

        Log::info('rubix_ml_training_started', [
            'samples' => count($features),
            'features' => count($features[0] ?? []),
        ]);

        $estimator->train($labeledDataset);

        // Create persistent model
        $persister = new Filesystem($this->modelStoragePath);
        $this->model = new PersistentModel($estimator, $persister);
        $this->saveModel();

        Log::info('rubix_ml_training_completed');
    }

    /**
     * Save the trained model to disk
     */
    private function saveModel(): void
    {
        if ($this->model instanceof PersistentModel) {
            $dir = dirname($this->modelStoragePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->model->save();
        }
    }

    /**
     * Prepare training data from historical trade outcomes
     */
    private function prepareTrainingData(CalibrationDataset $dataset): array
    {
        $features = [];
        $labels = [];

        $contexts = $this->extractContextsFromDataset($dataset);

        foreach ($contexts as $context) {
            $featureVector = $this->extractFeaturesForTraining($context);
            if ($featureVector === null) {
                continue;
            }

            // Simulate profitable/unprofitable label based on market conditions
            // In production, replace this with actual trade outcomes
            $label = $this->simulateTradeOutcome($context);

            $features[] = $featureVector;
            $labels[] = $label;
        }

        return [$features, $labels];
    }

    /**
     * Extract features for training (similar to prediction features)
     */
    private function extractFeaturesForTraining(array $context): ?array
    {
        $market = $context['market'] ?? [];
        $features = $context['features'] ?? [];

        $atr = $market['atr5m_pips'] ?? 0;
        $spread = $market['spread_estimate_pips'] ?? 0;
        $adx = $features['adx5m'] ?? 0;
        $emaZ = $features['ema20_z'] ?? 0;
        $trend = $features['trend30m'] ?? 'flat';

        if ($atr <= 0) {
            return null;
        }

        return [
            $atr,
            $spread,
            $spread / max($atr, 0.1),
            $adx,
            $emaZ,
            $trend === 'up' ? 1 : ($trend === 'down' ? -1 : 0),
            2.0, // Default RR
            1.0, // Default risk %
            2.0, // Default SL mult
            24,  // Default ADX min
            1,   // Default contrarian
            (int) date('H', strtotime($context['meta']['timestamp'] ?? '12:00')),
        ];
    }

    /**
     * Simulate trade outcome for training (replace with actual results in production)
     */
    private function simulateTradeOutcome(array $context): string
    {
        $market = $context['market'] ?? [];
        $features = $context['features'] ?? [];

        $adx = $features['adx5m'] ?? 0;
        $emaZ = abs($features['ema20_z'] ?? 0);
        $spread = $market['spread_estimate_pips'] ?? 0;
        $atr = $market['atr5m_pips'] ?? 0;

        // Simple heuristics for training labels
        $profitabilityScore = 0;

        // Higher ADX generally better
        $profitabilityScore += ($adx > 25) ? 1 : -1;

        // Lower spread better
        $profitabilityScore += ($spread < 1.5) ? 1 : -1;

        // Moderate volatility better
        $profitabilityScore += ($atr > 5 && $atr < 20) ? 1 : -1;

        // Avoid extreme Z-scores
        $profitabilityScore += ($emaZ < 1.0) ? 1 : -1;

        return $profitabilityScore > 0 ? 'profitable' : 'unprofitable';
    }

    /**
     * Calculate hit rate from predictions
     */
    private function calculateHitRate(array $predictions): float
    {
        if (empty($predictions)) {
            return 0.0;
        }

        $profitable = 0;
        foreach ($predictions as $prediction) {
            if ($prediction === 'profitable') {
                $profitable++;
            }
        }

        return $profitable / count($predictions);
    }

    /**
     * Calculate expectancy incorporating risk/reward
     */
    private function calculateExpectancy(array $predictions): float
    {
        if (empty($predictions)) {
            return 0.0;
        }

        $hitRate = $this->calculateHitRate($predictions);
        $avgWin = 2.0; // Assume 2R average win
        $avgLoss = -1.0; // Assume 1R average loss

        // Standard expectancy formula
        return ($hitRate * $avgWin) + ((1 - $hitRate) * $avgLoss);
    }

    /**
     * Estimate trading days from dataset
     */
    private function estimateTradingDays(CalibrationDataset $dataset): int
    {
        // Rough estimate: assume dataset covers 20 trading days (4 weeks)
        // In production, this could be computed from actual data timestamps
        return 20;
    }

    private function modelExists(): bool
    {
        return file_exists($this->modelStoragePath);
    }

    private function loadModel(): void
    {
        $persister = new Filesystem($this->modelStoragePath);
        $this->model = PersistentModel::load($persister);
    }

    /**
     * Create a minimal dummy model for testing/development when no training data is available
     */
    private function createDummyModel(): void
    {
        // Create minimal training data for a basic model
        $dummyFeatures = [
            [10, 1.0, 0.1, 25, 0.5, 0, 2.0, 1.0, 2.0, 24, 1, 12], // profitable case
            [5, 3.0, 0.6, 15, 2.0, 0, 1.5, 2.0, 1.5, 20, -1, 8],  // unprofitable case
            [8, 1.5, 0.2, 30, -0.5, 1, 2.5, 0.8, 2.5, 28, 1, 16], // profitable case
        ];

        $dummyLabels = ['profitable', 'unprofitable', 'profitable'];

        $labeledDataset = new Labeled($dummyFeatures, $dummyLabels);

        // Use Random Forest for consistency
        $estimator = new RandomForest;

        Log::info('rubix_ml_dummy_training_started', [
            'samples' => count($dummyFeatures),
            'features' => count($dummyFeatures[0]),
        ]);

        $estimator->train($labeledDataset);

        // Create persistent model
        $persister = new Filesystem($this->modelStoragePath);
        $this->model = new PersistentModel($estimator, $persister);
        $this->saveModel();

        Log::info('rubix_ml_dummy_training_completed');
    }
}
