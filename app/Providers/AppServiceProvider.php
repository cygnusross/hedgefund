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
            $driver = config('pricing.driver', 'twelvedata');

            return match ($driver) {
                'twelvedata' => new \App\Services\Prices\TwelveDataProvider(
                    config('pricing.twelvedata.api_key'),
                    config('pricing.twelvedata.base_url'),
                    (int) config('pricing.twelvedata.timeout', 8),
                ),
                'ig' => (class_exists('App\\Services\\Prices\\IgPriceProvider')
                    ? $app->make('App\\Services\\Prices\\IgPriceProvider')
                    : throw new \RuntimeException("Configured price provider [{$driver}] is not available.")),
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

        // Bind the EconomicCalendarProvider for fetching/caching economic calendar data
        $this->app->singleton(\App\Services\Economic\EconomicCalendarProvider::class, function ($app) {
            return new \App\Services\Economic\EconomicCalendarProvider;
        });

        // Bind the EconomicCalendarProviderContract to the concrete implementation
        $this->app->bind(
            \App\Services\Economic\EconomicCalendarProviderContract::class,
            fn ($app) => $app->make(\App\Services\Economic\EconomicCalendarProvider::class)
        );

        // Bind CandleUpdaterContract to the IncrementalCandleUpdater so it can be injected by interface
        $this->app->bind(
            \App\Application\Candles\CandleUpdaterContract::class,
            fn ($app) => $app->make(\App\Application\Candles\IncrementalCandleUpdater::class)
        );

        // Bind CandleCache contract to adapter for DI
        $this->app->bind(
            \App\Infrastructure\Prices\CandleCacheContract::class,
            \App\Infrastructure\Prices\CandleCacheAdapter::class
        );

        // Bind AlphaRules loader as a singleton
        $this->app->singleton(\App\Domain\Rules\AlphaRules::class, function ($app) {
            $path = env('RULES_YAML_PATH', storage_path('app/alpha_rules.yaml'));
            $rules = new \App\Domain\Rules\AlphaRules($path);
            // Attempt initial load but do not fail bootstrap if file absent; let reload() throw when called explicitly
            try {
                $rules->reload();
            } catch (\Throwable $e) {
                // log but do not break bootstrapping
                \Illuminate\Support\Facades\Log::warning('alpha_rules_initial_load_failed', ['error' => $e->getMessage()]);
            }

            return $rules;
        });

        // Bind ClientSentimentProvider for DI and easier test mocking
        $this->app->bind(\App\Services\IG\ClientSentimentProvider::class, function ($app) {
            return new \App\Services\IG\ClientSentimentProvider(
                $app->make(\App\Services\IG\Endpoints\ClientSentimentEndpoint::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class)
            );
        });

        // Bind PositionLedgerContract to a NullPositionLedger by default so DecisionEngine can resolve it
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
