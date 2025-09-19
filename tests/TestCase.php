<?php

namespace Tests;

use App\Domain\Market\Contracts\MarketStatusProvider as MarketStatusProviderContract;
use App\Services\MarketStatus\NullMarketStatusProvider;
use App\Domain\Sentiment\Contracts\SentimentProvider as SentimentProviderContract;
use App\Services\Sentiment\NullSentimentProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        app()->bind(MarketStatusProviderContract::class, NullMarketStatusProvider::class);
        app()->bind(SentimentProviderContract::class, NullSentimentProvider::class);
    }
}
