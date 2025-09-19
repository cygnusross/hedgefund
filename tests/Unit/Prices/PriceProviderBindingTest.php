<?php

use App\Services\Prices\IgPriceProvider;
use App\Services\Prices\PriceProvider;
use App\Services\Prices\TwelveDataProvider;

uses(Tests\TestCase::class);

it('resolves the PriceProvider binding to TwelveDataProvider when configured', function () {
    config(['pricing.driver' => 'twelvedata']);

    $this->app->register(\App\Providers\AppServiceProvider::class);

    $provider = app(PriceProvider::class);

    expect($provider)->toBeInstanceOf(TwelveDataProvider::class);
});

it('resolves the PriceProvider binding to IgPriceProvider when configured', function () {
    config(['pricing.driver' => 'ig']);

    $this->app->register(\App\Providers\AppServiceProvider::class);

    $provider = app(PriceProvider::class);

    expect($provider)->toBeInstanceOf(IgPriceProvider::class);
});
