<?php

namespace Tests;

use App\Domain\Market\Contracts\MarketStatusProvider as MarketStatusProviderContract;
use App\Domain\Rules\Calibration\RubixMLPipeline;
use App\Domain\Sentiment\Contracts\SentimentProvider as SentimentProviderContract;
use App\Services\MarketStatus\NullMarketStatusProvider;
use App\Services\Sentiment\NullSentimentProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset RubixML model state for each test to prevent cross-test pollution
        RubixMLPipeline::resetModelState();

        // Force PHP timezone to application timezone for consistent tests
        try {
            $tz = config('app.timezone');
            if ($tz) {
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Default tests to TwelveData provider to avoid external IG calls unless overridden per-test
        config([
            'backtest.enabled' => false,
            'pricing.driver' => 'twelvedata',
            'pricing.bootstrap_limit_5min' => 120,
            'pricing.bootstrap_limit_30min' => 30,
            'pricing.tail_fetch_limit_5min' => 10,
            'pricing.tail_fetch_limit_30min' => 6,
        ]);

        // Fake all external HTTP calls to prevent network requests during tests
        Http::fake([
            'https://demo-api.ig.com/*' => Http::response([
                'snapshot' => [
                    'bid' => 11750,
                    'offer' => 11755,
                    'netChange' => 0.0005,
                    'pctChange' => 0.0042,
                    'updateTime' => '10:30:00',
                    'delayTime' => 0,
                    'marketStatus' => 'TRADEABLE',
                ],
                'dealingRules' => [
                    'minStepDistance' => ['value' => 5, 'unit' => 'POINTS'],
                    'minDealSize' => ['value' => 0.5, 'unit' => 'AMOUNT'],
                    'minControlledRiskStopDistance' => ['value' => 10, 'unit' => 'POINTS'],
                    'minNormalStopOrLimitDistance' => ['value' => 8, 'unit' => 'POINTS'],
                ],
            ], 200),
            'api.twelvedata.com/*' => Http::response([
                'meta' => ['symbol' => 'EURUSD', 'interval' => '5min'],
                'values' => [],
                'status' => 'ok',
            ], 200),
            config('economic.calendar_url', 'https://example.com/calendar') => Http::response([
                ['title' => 'Sample Event', 'currency' => 'USD', 'impact' => 'High'],
            ], 200),
        ]);

        app()->bind(MarketStatusProviderContract::class, NullMarketStatusProvider::class);
        app()->bind(SentimentProviderContract::class, NullSentimentProvider::class);

        // Speed up calibration-related tests by shrinking candidate budgets
        // and disabling Monte Carlo runs in the testing environment.
        config([
            'calibration.budgets.stage1_count' => 30, // Small but sufficient for meaningful results
            'calibration.budgets.stage2_count' => 20,
            'calibration.budgets.top_n_mc' => 5,
            'calibration.budgets.mc_runs' => 1,
            'calibration.budgets.min_trades_per_day' => 0.3, // Use normal threshold for proper filtering
            'calibration.skip_mc_when_testing' => true,
            // Reduce logging noise in tests
            'logging.default' => 'null',
        ]);
    }
}
