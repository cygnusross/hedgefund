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

use App\Application\Candles\DatabaseCandleProvider;
use App\Domain\Decision\Contracts\DecisionContextContract;
use App\Domain\Decision\Contracts\LiveDecisionEngineContract;
use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;
use App\Domain\Sentiment\Contracts\SentimentProvider as SentimentProviderContract;
use App\Support\Clock\ClockInterface;
use App\Support\Clock\SystemClock;
use App\Domain\Market\Contracts\MarketStatusProvider as MarketStatusProviderContract;
use App\Services\IG\ClientSentimentProvider as IgSentimentProvider;
use App\Services\Economic\DatabaseEconomicCalendarProvider;
use App\Services\MarketStatus\DatabaseMarketStatusProvider;
use App\Services\MarketStatus\IgMarketStatusProvider;
use App\Services\Prices\DatabasePriceProvider;
use App\Services\Sentiment\DatabaseSentimentProvider;
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

        // Bind the NewsProvider implementation based on config/news.php
        $this->app->singleton(\App\Services\News\NewsProvider::class, function ($app) {
            $driver = config('news.default', 'forexnewsapi');

            return match ($driver) {
                'forexnewsapi' => new \App\Services\News\ForexNewsApiProvider,
                default => throw new \RuntimeException("Unknown news provider [{$driver}] configured in config/news.php"),
            };
        });

        // Bind NewsService for dependency injection
        $this->app->bind(\App\Application\News\NewsServiceInterface::class, \App\Application\News\NewsService::class);

        // Bind IG Historical Prices Endpoint
        $this->app->bind(\App\Services\IG\Endpoints\HistoricalPricesEndpoint::class, function ($app) {
            return new \App\Services\IG\Endpoints\HistoricalPricesEndpoint(
                $app->make(\App\Services\IG\Client::class)
            );
        });

        // Bind the EconomicCalendarProvider for fetching/caching economic calendar data
        $this->app->singleton(\App\Services\Economic\EconomicCalendarProvider::class, function ($app) {
            if ($this->backtestEnabled()) {
                return new DatabaseEconomicCalendarProvider();
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

        // Bind AlphaRules loader as a singleton
        $this->app->singleton(AlphaRules::class, function ($app) {
            $path = env('RULES_YAML_PATH', storage_path('app/alpha_rules.yaml'));
            $rules = new AlphaRules($path);
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
