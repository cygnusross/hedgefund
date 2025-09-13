<?php

namespace App\Providers;

use App\Domain\FX\SpreadEstimator;
use Illuminate\Support\ServiceProvider;

class SpreadServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SpreadEstimator::class, function ($app) {
            // Resolve IG client - the application already provides IG client bindings elsewhere
            $ig = $app->make(\App\Services\IG\Client::class);

            // Use the default cache repository
            $cache = $app['cache']->store();

            // PSR logger from container
            $logger = $app->make(\Psr\Log\LoggerInterface::class);

            return new SpreadEstimator($ig, $cache, $logger);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // nothing to boot
    }
}
