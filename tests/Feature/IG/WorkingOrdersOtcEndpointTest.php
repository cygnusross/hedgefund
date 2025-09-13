<?php

use App\Services\IG\Endpoints\WorkingOrdersOtcEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('creates an otc working order and returns dealReference', function () {
    Cache::flush();
    $username = config('services.ig.username') ?? 'default';
    Cache::put('ig_session:'.$username.':CST', 'seed-cst', now()->addHours(12));
    Cache::put('ig_session:'.$username.':X-SECURITY-TOKEN', 'seed-x', now()->addHours(12));

    $config = config('services.ig', []);
    $demoActive = data_get($config, 'demo.active', true);
    $expectedBase = $demoActive
        ? (rtrim(data_get($config, 'demo.base_url', 'https://demo-api.ig.com/gateway/deal'), '/'))
        : (rtrim(data_get($config, 'base_url', 'https://api.ig.com/gateway/deal'), '/'));

    $requestPayload = [
        'request' => [
            'currencyCode' => 'GBP',
            'direction' => 'BUY',
            'epic' => 'CS.D.EURUSD.MINI.IP',
            'expiry' => '-',
            'guaranteedStop' => false,
            'level' => 1.2345,
            'size' => 1,
            'type' => 'LIMIT',
        ],
    ];

    Http::fake(function ($request) use ($expectedBase) {
        expect(str_starts_with($request->url(), $expectedBase))->toBeTrue();

        // Incoming body should include 'request'
        $body = json_decode((string) $request->body(), true) ?? [];
        expect(isset($body['request']))->toBeTrue();

        return Http::response([
            'dealReference' => 'DR-12345',
        ], 200);
    });

    $endpoint = app(WorkingOrdersOtcEndpoint::class);
    $result = $endpoint->create($requestPayload);

    expect($result['dealReference'])->toBe('DR-12345');
});
