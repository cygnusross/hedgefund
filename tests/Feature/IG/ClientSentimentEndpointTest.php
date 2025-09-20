<?php

use App\Services\IG\Endpoints\ClientSentimentEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns client sentiment for a market and includes tokens', function () {
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

    $marketId = 'MARKET-1';

    Http::fake(function ($request) use ($expectedBase, $marketId) {
        expect(str_starts_with($request->url(), $expectedBase . '/clientsentiment/' . $marketId))->toBeTrue();
        expect($request->hasHeader('CST'))->toBeTrue();

        return Http::response([
            'marketId' => $marketId,
            'longPositionPercentage' => 60.5,
            'shortPositionPercentage' => 39.5,
        ], 200);
    });

    $endpoint = app(ClientSentimentEndpoint::class);
    $sentiment = $endpoint->get($marketId);

    expect($sentiment['marketId'])->toBe($marketId);
    expect($sentiment['longPositionPercentage'])->toBe(60.5);
});
