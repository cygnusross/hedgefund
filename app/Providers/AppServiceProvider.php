<?php

/*
TASK: Bind the ClientSentimentProvider via the container so tests can mock it.

- $this->app->bind(\App\Services\IG\ClientSentimentProvider::class, function ($app) {
     return new \App\Services\IG\ClientSentimentProvider(
        $app->make(\App\Services\IG\Endpoints\ClientSentimentEndpoint::class),
        $app->make(\Illuminate\Contracts\Cache\Repository::class)
     );
  });
  */

namespace App\Providers;

use App\Domain\Decision\Contracts\DecisionContextContract;
use App\Domain\Decision\Contracts\LiveDecisionEngineContract;
use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Market\Contracts\MarketStatusProvider as MarketStatusProviderContract;
use App\Domain\Rules\AlphaRules;
use App\Domain\Rules\Calibration\CalibrationPipeline;
use App\Domain\Rules\Calibration\CandidateGenerator;
use App\Domain\Rules\Calibration\CandidateScorer;
use App\Domain\Rules\Calibration\FeatureSnapshotService;
use App\Domain\Rules\Calibration\MonteCarloEvaluator;
use App\Domain\Rules\Calibration\RubixMLPipeline;
use App\Domain\Rules\RuleContextManager;
use App\Domain\Rules\RuleResolver;
use App\Domain\Rules\RuleSetRepository;
use App\Domain\Sentiment\Contracts\SentimentProvider as SentimentProviderContract;
use App\Services\Economic\DatabaseEconomicCalendarProvider;
use App\Services\IG\ClientSentimentProvider as IgSentimentProvider;
use App\Services\MarketStatus\IgMarketStatusProvider;
use App\Support\Clock\ClockInterface;
use App\Support\Clock\SystemClock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the PriceProvider implementation based on config/pricing.php
        $this->app->singleton(\App\Services\Prices\PriceProvider::class, function ($app) {
            $driver = $this->backtestEnabled()
                ? 'database'
                : config('pricing.driver', 'twelvedata');

            return match ($driver) {
                'twelvedata' => new \App\Services\Prices\TwelveDataProvider(
                    config('pricing.twelvedata.api_key'),
                    config('pricing.twelvedata.base_url'),
                    (int) config('pricing.twelvedata.timeout', 8),
                ),
                'ig' => $app->make(\App\Services\Prices\IgPriceProvider::class),
                'database' => new \App\Services\Prices\DatabasePriceProvider(
                    $app->make(\App\Application\Candles\DatabaseCandleProvider::class)
                ),
                default => throw new \RuntimeException("Unknown price provider [{$driver}] configured in config/pricing.php"),
            };
        });

        // Bind IG Historical Prices Endpoint
        $this->app->bind(\App\Services\IG\Endpoints\HistoricalPricesEndpoint::class, function ($app) {
            return new \App\Services\IG\Endpoints\HistoricalPricesEndpoint(
                $app->make(\App\Services\IG\Client::class)
            );
        });

        // Bind the EconomicCalendarProvider for fetching/caching economic calendar data
        $this->app->singleton(\App\Services\Economic\EconomicCalendarProvider::class, function ($app) {
            if ($this->backtestEnabled()) {
                return new DatabaseEconomicCalendarProvider;
            }

            return new \App\Services\Economic\EconomicCalendarProvider;
        });

        $this->app->bind(
            \App\Services\Economic\EconomicCalendarProviderContract::class,
            fn ($app) => $app->make(\App\Services\Economic\EconomicCalendarProvider::class)
        );

        // Bind CandleUpdaterContract to the IncrementalCandleUpdater so it can be injected by interface
        $this->app->bind(
            \App\Application\Candles\CandleUpdaterContract::class,
            function ($app) {
                if ($this->backtestEnabled()) {
                    return $app->make(\App\Application\Candles\DatabaseCandleProvider::class);
                }

                return $app->make(\App\Application\Candles\IncrementalCandleUpdater::class);
            }
        );

        // Bind CandleCache contract to adapter for DI
        $this->app->bind(
            \App\Infrastructure\Prices\CandleCacheContract::class,
            \App\Infrastructure\Prices\CandleCacheAdapter::class
        );

        $this->app->bind(DecisionContextContract::class, DecisionContext::class);

        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->app->bind(LiveDecisionEngineContract::class, function ($app) {
            $rules = $app->make(AlphaRules::class);
            $clock = $app->make(ClockInterface::class);
            $ledger = $app->bound(PositionLedgerContract::class)
                ? $app->make(PositionLedgerContract::class)
                : null;

            return new LiveDecisionEngine($rules, $clock, $ledger);
        });

        $this->app->singleton(RuleSetRepository::class, fn () => new RuleSetRepository);

        $this->app->singleton(RuleResolver::class, function ($app) {
            $cacheFactory = $app->make(CacheFactory::class);
            $defaultStore = config('cache.default');

            $store = $cacheFactory->store();
            if ($defaultStore === 'redis' && ! class_exists(\Redis::class)) {
                $store = $cacheFactory->store('array');
            }

            return new RuleResolver(
                $app->make(RuleSetRepository::class),
                $store
            );
        });

        $this->app->singleton(RuleContextManager::class, fn ($app) => new RuleContextManager($app->make(RuleResolver::class)));

        $this->app->singleton(FeatureSnapshotService::class, fn () => new FeatureSnapshotService);
        $this->app->singleton(CandidateGenerator::class, fn () => new CandidateGenerator);
        $this->app->singleton(RubixMLPipeline::class, fn () => new RubixMLPipeline);
        $this->app->singleton(CandidateScorer::class, fn ($app) => new CandidateScorer($app->make(RubixMLPipeline::class)));
        $this->app->singleton(MonteCarloEvaluator::class, fn () => new MonteCarloEvaluator);

        $this->app->singleton(CalibrationPipeline::class, function ($app) {
            return new CalibrationPipeline(
                $app->make(RuleResolver::class),
                $app->make(FeatureSnapshotService::class),
                $app->make(CandidateGenerator::class),
                $app->make(CandidateScorer::class),
                $app->make(MonteCarloEvaluator::class)
            );
        });

        // Bind AlphaRules loader as a singleton
        $this->app->singleton(AlphaRules::class, function ($app) {
            $path = env('RULES_YAML_PATH', storage_path('app/alpha_rules.yaml'));
            $resolver = $app->make(RuleResolver::class);
            $rules = new AlphaRules($path, $resolver);
            // Attempt initial load but do not fail bootstrap if file absent; let reload() throw when called explicitly
            try {
                $rules->reload();
            } catch (\Throwable $e) {
                // log but do not break bootstrapping
                \Illuminate\Support\Facades\Log::warning('alpha_rules_initial_load_failed', ['error' => $e->getMessage()]);
            }

            return $rules;
        });

        $this->app->bind(IgSentimentProvider::class, function ($app) {
            return new IgSentimentProvider(
                $app->make(\App\Services\IG\Endpoints\ClientSentimentEndpoint::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class)
            );
        });

        $this->app->bind(SentimentProviderContract::class, function ($app) {
            if ($this->backtestEnabled()) {
                return $app->make(\App\Services\Sentiment\DatabaseSentimentProvider::class);
            }

            return $app->make(IgSentimentProvider::class);
        });

        $this->app->bind(MarketStatusProviderContract::class, function ($app) {
            if ($this->backtestEnabled()) {
                return $app->make(\App\Services\MarketStatus\DatabaseMarketStatusProvider::class);
            }

            return new IgMarketStatusProvider(
                $app->make(\App\Services\IG\Endpoints\MarketsEndpoint::class),
                $app['cache']->store()
            );
        });

        // Bind PositionLedgerContract to a NullPositionLedger by default so LiveDecisionEngine can resolve it
        $this->app->bind(\App\Domain\Execution\PositionLedgerContract::class, function ($app) {
            return new \App\Domain\Execution\NullPositionLedger;
        });

        // Bind WorkingOrderService with its dependencies
        $this->app->bind(\App\Services\IG\WorkingOrderService::class, function ($app) {
            return new \App\Services\IG\WorkingOrderService(
                $app->make(\App\Services\IG\Client::class),
                $app->make(\App\Services\IG\Endpoints\WorkingOrdersOtcEndpoint::class)
            );
        });
    }

    private function backtestEnabled(): bool
    {
        return (bool) config('backtest.enabled', false) || env('BACKTEST_MODE', false);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ensure PHP's default timezone matches the application timezone configured in config/app.php
        try {
            $tz = config('app.timezone');
            if ($tz) {
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
