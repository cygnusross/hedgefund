<?php

namespace App\Services\IG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Client
{
    protected array $config;

    public function __construct(array $config = [])
    {
        // Merge provided config with config/services.php ig entry
        $this->config = array_merge(config('services.ig', []), $config);
    }

    protected function baseUrl(): string
    {
        $demoActive = data_get($this->config, 'demo.active', true);

        return $demoActive
            ? (rtrim(data_get($this->config, 'demo.base_url', 'https://demo-api.ig.com/gateway/deal'), '/'))
            : (rtrim(data_get($this->config, 'base_url', 'https://api.ig.com/gateway/deal'), '/'));
    }

    protected function defaultHeaders(): array
    {
        $demoActive = data_get($this->config, 'demo.active', true);

        // Prefer demo.key when demo is active, otherwise top-level key
        $apiKey = $demoActive ? (data_get($this->config, 'demo.key') ?? ($this->config['key'] ?? '')) : ($this->config['key'] ?? '');

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-IG-API-KEY' => $apiKey,
        ];

        // Inject cached tokens if available
        $cst = $this->getCachedCst();
        $xToken = $this->getCachedXSecurityToken();

        // Backwards-compatibility: tests or other code may seed cache with a simpler
        // 'ig_session:{username}:CST' key derived from config('services.ig.username') or 'default'.
        // If our prefixed lookup didn't find tokens, try the legacy keys as a fallback.
        if (! $cst) {
            $legacyUsername = config('services.ig.username') ?? 'default';
            $cst = Cache::get('ig_session:'.$legacyUsername.':CST');
        }

        if (! $xToken) {
            $legacyUsername = config('services.ig.username') ?? 'default';
            $xToken = Cache::get('ig_session:'.$legacyUsername.':X-SECURITY-TOKEN');
        }

        if ($cst) {
            $headers['CST'] = $cst;
        }

        if ($xToken) {
            $headers['X-SECURITY-TOKEN'] = $xToken;
        }

        return $headers;
    }

    protected function cacheKeyPrefix(): string
    {
        $demoActive = data_get($this->config, 'demo.active', true);

        if ($demoActive) {
            $username = data_get($this->config, 'demo.username') ?? ($this->config['username'] ?? 'default');
        } else {
            $username = $this->config['username'] ?? 'default';
        }

        return 'ig_session:'.$username;
    }

    public function post(string $path, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $response = Http::withHeaders(array_merge($this->defaultHeaders(), $headers))
            ->post($url, $data);

        $response->throw();

        return [
            'headers' => $this->extractResponseHeaders($response),
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $response = Http::withHeaders(array_merge($this->defaultHeaders(), $headers))
            ->get($url, $query);

        $response->throw();

        return [
            'headers' => $this->extractResponseHeaders($response),
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    protected function extractResponseHeaders($response): array
    {
        // Illuminate HTTP client has header() but to be defensive, use toPsrResponse when available
        try {
            $psr = $response->toPsrResponse();
            $headers = [];
            foreach ($psr->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            return $headers;
        } catch (\Throwable $e) {
            // Fallback
            return [];
        }
    }

    /**
     * Create a session by calling POST /session and cache the CST and X-SECURITY-TOKEN
     * Returns the accountInfo body on success
     *
     * @param  array  $credentials  ['identifier' => '', 'password' => '', 'encryptedPassword' => false]
     */
    public function createSession(array $credentials): array
    {
        try {
            $result = $this->post('/session', ['authenticationRequest' => $credentials]);
        } catch (\Throwable $e) {
            // If the API returns a 400 validation error about authenticationRequest.password being null,
            // try an alternate payload shape (top-level fields). This handles subtle differences between demo/live endpoints.
            try {
                $result = $this->post('/session', $credentials);
            } catch (\Throwable $e2) {
                throw $e2;
            }
        }

        $headers = $result['headers'] ?? [];
        $body = $result['body'] ?? [];

        $cst = $headers['CST'] ?? ($headers['cst'] ?? null);
        $xToken = $headers['X-SECURITY-TOKEN'] ?? ($headers['X-Security-Token'] ?? ($headers['x-security-token'] ?? null));

        if ($cst && $xToken) {
            $prefix = $this->cacheKeyPrefix();
            // Store tokens in cache (Redis) with a 12 hour TTL (IG tokens expire every 12 hours).
            Cache::put($prefix.':CST', $cst, now()->addHours(12));
            Cache::put($prefix.':X-SECURITY-TOKEN', $xToken, now()->addHours(12));
        }

        return $body['accountInfo'] ?? $body;
    }

    public function getCachedCst(): ?string
    {
        return Cache::get($this->cacheKeyPrefix().':CST');
    }

    public function getCachedXSecurityToken(): ?string
    {
        return Cache::get($this->cacheKeyPrefix().':X-SECURITY-TOKEN');
    }

    /**
     * Ensure a session exists. If tokens are missing and credentials are provided, create a new session.
     * Returns true when valid tokens exist after this call.
     */
    public function ensureSession(?array $credentials = null): bool
    {
        if ($this->getCachedCst() && $this->getCachedXSecurityToken()) {
            return true;
        }

        if ($credentials === null) {
            return false;
        }

        try {
            $this->createSession($credentials);

            return (bool) ($this->getCachedCst() && $this->getCachedXSecurityToken());
        } catch (\Throwable $e) {
            return false;
        }
    }
}
