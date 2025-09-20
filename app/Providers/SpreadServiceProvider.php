<?php

namespace App\Providers;

use App\Domain\FX\DatabaseSpreadEstimator;
use App\Domain\FX\SpreadEstimator;
use Illuminate\Support\ServiceProvider;

class SpreadServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Domain\FX\Contracts\SpreadEstimatorContract::class, function ($app) {
            if ($this->backtestEnabled()) {
                return new DatabaseSpreadEstimator;
            }

            $ig = $app->make(\App\Services\IG\Client::class);
            $cache = $app['cache']->store();
            $logger = $app->make(\Psr\Log\LoggerInterface::class);

            return new SpreadEstimator($ig, $cache, $logger);
        });

        $this->app->alias(\App\Domain\FX\Contracts\SpreadEstimatorContract::class, SpreadEstimator::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // nothing to boot
    }

    private function backtestEnabled(): bool
    {
        return (bool) config('backtest.enabled', false) || env('BACKTEST_MODE', false);
    }
}
