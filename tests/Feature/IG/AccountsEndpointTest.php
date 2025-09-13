<?php

use App\Services\IG\Endpoints\AccountsEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns accounts and includes cached tokens in request', function () {
    // Seed cache tokens
    Cache::flush();
    $username = config('services.ig.username') ?? 'default';
    Cache::put('ig_session:'.$username.':CST', 'seed-cst', now()->addHours(12));
    Cache::put('ig_session:'.$username.':X-SECURITY-TOKEN', 'seed-x', now()->addHours(12));

    $config = config('services.ig', []);
    $demoActive = data_get($config, 'demo.active', true);
    $expectedBase = $demoActive
        ? (rtrim(data_get($config, 'demo.base_url', 'https://demo-api.ig.com/gateway/deal'), '/'))
        : (rtrim(data_get($config, 'base_url', 'https://api.ig.com/gateway/deal'), '/'));

    Http::fake(function ($request) use ($expectedBase) {
        // Ensure URL and headers include tokens
        expect(str_starts_with($request->url(), $expectedBase))->toBeTrue();
        expect($request->hasHeader('CST'))->toBeTrue();
        expect($request->hasHeader('X-SECURITY-TOKEN'))->toBeTrue();

        return Http::response([
            'accounts' => [
                [
                    'accountId' => 'ABC123',
                    'accountName' => 'Primary',
                    'accountType' => 'CFD',
                    'balance' => [
                        'available' => 1000,
                        'balance' => 1000,
                    ],
                    'preferred' => true,
                    'status' => 'ENABLED',
                ],
            ],
        ], 200);
    });

    $endpoint = app(AccountsEndpoint::class);
    $accounts = $endpoint->list();

    expect($accounts)->toBeArray();
    expect($accounts[0]['accountId'])->toBe('ABC123');
});
