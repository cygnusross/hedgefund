<?php

use App\Services\Prices\PriceProvider;
use App\Services\Prices\TwelveDataProvider;

uses(Tests\TestCase::class);

it('resolves the PriceProvider binding to TwelveDataProvider', function () {
    // Ensure the AppServiceProvider is registered in the test container
    $this->app->register(\App\Providers\AppServiceProvider::class);

    if (! app()->bound(PriceProvider::class)) {
        $this->fail('PriceProvider is not bound after registering AppServiceProvider. config/pricing: '.json_encode(config('pricing')));
    }

    $provider = app(PriceProvider::class);

    expect($provider)->toBeInstanceOf(TwelveDataProvider::class);
});
