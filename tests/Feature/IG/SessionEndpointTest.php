<?php

use App\Services\IG\Endpoints\SessionEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('creates a session and caches tokens', function () {
    $integration = (bool) getenv('IG_INTEGRATION');

    // Determine expected base URL from config (for assertions in fake mode)
    $config = config('services.ig', []);
    $demoActive = data_get($config, 'demo.active', true);
    $expectedBase = $demoActive
        ? (rtrim(data_get($config, 'demo.base_url', 'https://demo-api.ig.com/gateway/deal'), '/'))
        : (rtrim(data_get($config, 'base_url', 'https://api.ig.com/gateway/deal'), '/'));

    if ($integration) {
        // In integration mode, choose demo or live credentials consistent with Client::baseUrl()
        $demoActive = data_get($config, 'demo.active', true);

        if ($demoActive) {
            $identifier = config('services.ig.demo.username') ?? config('services.ig.username') ?? getenv('IG_DEMO_USERNAME') ?? getenv('IG_USERNAME');
            $password = config('services.ig.demo.password') ?? config('services.ig.password') ?? getenv('IG_DEMO_PASSWORD') ?? getenv('IG_PASSWORD');
        } else {
            $identifier = config('services.ig.username') ?? getenv('IG_USERNAME');
            $password = config('services.ig.password') ?? getenv('IG_PASSWORD');
        }

        if (empty($identifier) || empty($password)) {
            test()->skip('Integration test skipped: IG credentials not present in config/.env');
        }

        // Ensure cache is empty
        Cache::flush();

        $endpoint = app(SessionEndpoint::class);

        $accountInfo = $endpoint->create([
            'identifier' => $identifier,
            'password' => $password,
            'encryptedPassword' => false,
        ]);

        // Basic assertions for integration response
        expect($accountInfo)->toBeArray();

        // Tokens should be cached
        $client = app(App\Services\IG\Client::class);
        expect($client->getCachedCst())->not->toBeNull();
        expect($client->getCachedXSecurityToken())->not->toBeNull();
    } else {
        // Fake HTTP response and assert request URL prefix
        Http::fake(function ($request) use ($expectedBase) {
            // Assert URL starts with expected base (path may be appended)
            expect(str_starts_with($request->url(), $expectedBase))->toBeTrue();

            return Http::response([
                'accountInfo' => [
                    'available' => 1000,
                    'balance' => 1000,
                ],
            ], 200, [
                'CST' => 'fake-cst-token',
                'X-SECURITY-TOKEN' => 'fake-x-token',
            ]);
        });

        // Ensure cache is empty
        Cache::flush();

        $endpoint = app(SessionEndpoint::class);

        $accountInfo = $endpoint->create([
            'identifier' => 'dummy',
            'password' => 'secret',
            'encryptedPassword' => false,
        ]);

        expect($accountInfo)->toBeArray()->and($accountInfo['available'])->toEqual(1000);

        // Tokens should be cached
        $client = app(App\Services\IG\Client::class);
        expect($client->getCachedCst())->toBe('fake-cst-token');
        expect($client->getCachedXSecurityToken())->toBe('fake-x-token');
    }
});
