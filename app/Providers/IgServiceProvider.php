<?php

namespace App\Providers;

use App\Services\IG\Client;
use Illuminate\Support\ServiceProvider;

class IgServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton(Client::class, function ($app) {
            // Pull config from config/services.php
            $config = config('services.ig', []);

            // Allow overriding demo base URLs from config/services.php
            if (! isset($config['demo']['base_url'])) {
                $config['demo']['base_url'] = env('IG_DEMO_BASE_URL', 'https://demo-api.ig.com/gateway/deal');
            }

            if (! isset($config['base_url'])) {
                $config['base_url'] = env('IG_BASE_URL', 'https://api.ig.com/gateway/deal');
            }

            return new Client($config);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        // no-op
    }
}
