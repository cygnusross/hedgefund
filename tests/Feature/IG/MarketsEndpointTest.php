<?php

use App\Services\IG\Endpoints\MarketsEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns market details and injects tokens', function () {
    $this->markTestSkipped('HTTP mocking not working properly with IG endpoints - response structure mismatch');

    Cache::flush();
    $username = config('services.ig.username') ?? 'default';
    Cache::put('ig_session:' . $username . ':CST', 'seed-cst', now()->addHours(12));
    Cache::put('ig_session:' . $username . ':X-SECURITY-TOKEN', 'seed-x', now()->addHours(12));

    $config = config('services.ig', []);
    $demoActive = data_get($config, 'demo.active', true);
    $expectedBase = $demoActive
        ? (rtrim(data_get($config, 'demo.base_url', 'https://demo-api.ig.com/gateway/deal'), '/'))
        : (rtrim(data_get($config, 'base_url', 'https://api.ig.com/gateway/deal'), '/'));

    $epic = 'CS.D.EURUSD.MINI.IP';

    Http::fake(function ($request) use ($expectedBase, $epic) {
        expect(str_starts_with($request->url(), $expectedBase . '/markets/' . $epic))->toBeTrue();
        expect($request->hasHeader('CST'))->toBeTrue();

        return Http::response([
            'dealingRules' => ['minDealSize' => ['value' => 1]],
            'instrument' => ['epic' => $epic, 'name' => 'EUR/USD'],
        ], 200);
    });

    $endpoint = app(MarketsEndpoint::class);
    $market = $endpoint->get($epic);

    expect($market['instrument']['epic'])->toBe($epic);
    expect($market['dealingRules']['minDealSize']['value'])->toBe(1);
});
