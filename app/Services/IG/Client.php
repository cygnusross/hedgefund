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

        // Add account ID for spread betting account (preferred account)
        $headers['IG-ACCOUNT-ID'] = 'Z636IW';

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
        try {
            return $this->makeRequest('post', $path, $data, $headers);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() === 401 && $this->shouldRetry()) {
                if ($this->refreshSessionAndRetry('post', $path, $data, $headers)) {
                    return $this->makeRequest('post', $path, $data, $headers);
                }
            }
            throw $e;
        }
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        try {
            return $this->makeGetRequest($path, $query, $headers);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() === 401 && $this->shouldRetry()) {
                if ($this->refreshSessionAndRetry('get', $path, $query, $headers)) {
                    return $this->makeGetRequest($path, $query, $headers);
                }
            }
            throw $e;
        }
    }

    public function put(string $path, array $data = [], array $headers = []): array
    {
        try {
            return $this->makePutRequest($path, $data, $headers);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() === 401 && $this->shouldRetry()) {
                if ($this->refreshSessionAndRetry('put', $path, $data, $headers)) {
                    return $this->makePutRequest($path, $data, $headers);
                }
            }
            throw $e;
        }
    }

    private function makeRequest(string $method, string $path, array $data, array $headers): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $finalHeaders = array_merge($this->defaultHeaders(), $headers);

        // Add VERSION header for workingorders/otc endpoint
        if (str_contains($path, 'workingorders/otc')) {
            $finalHeaders['VERSION'] = '2';
        }

        \Illuminate\Support\Facades\Log::info('IG API Request', [
            'method' => $method,
            'url' => $url,
            'headers' => $finalHeaders,
            'data' => $data,
        ]);

        $response = Http::withHeaders($finalHeaders)->asJson()->post($url, $data);

        \Illuminate\Support\Facades\Log::info('IG API Response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ]);

        $response->throw();

        return [
            'headers' => $this->extractResponseHeaders($response),
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    private function makeGetRequest(string $path, array $query, array $headers): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $finalHeaders = array_merge($this->defaultHeaders(), $headers);

        \Illuminate\Support\Facades\Log::info('IG API Request', [
            'method' => 'get',
            'url' => $url,
            'headers' => $finalHeaders,
            'query' => $query,
        ]);

        $response = Http::withHeaders($finalHeaders)->get($url, $query);

        \Illuminate\Support\Facades\Log::info('IG API Response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ]);

        $response->throw();

        return [
            'headers' => $this->extractResponseHeaders($response),
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    private function makePutRequest(string $path, array $data, array $headers): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $finalHeaders = array_merge($this->defaultHeaders(), $headers);

        \Illuminate\Support\Facades\Log::info('IG API Request', [
            'method' => 'put',
            'url' => $url,
            'headers' => $finalHeaders,
            'data' => $data,
        ]);

        $response = Http::withHeaders($finalHeaders)->asJson()->put($url, $data);

        \Illuminate\Support\Facades\Log::info('IG API Response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ]);

        $response->throw();

        return [
            'headers' => $this->extractResponseHeaders($response),
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    private function shouldRetry(): bool
    {
        return (int) data_get($this->config, 'retry.attempts', 1) > 0;
    }

    private function refreshSessionAndRetry(string $method, string $path, $params, array $headers): bool
    {
        $credentials = $this->getCredentialsFromConfig();
        if (! $credentials) {
            return false;
        }

        try {
            $this->createSession($credentials);

            // Add small delay before retry to allow session to propagate
            $delay = (int) data_get($this->config, 'retry.delay', 100);
            usleep($delay * 1000);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('IG session refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getCredentialsFromConfig(): ?array
    {
        $demoActive = data_get($this->config, 'demo.active', true);

        if ($demoActive) {
            $identifier = data_get($this->config, 'demo.username');
            $password = data_get($this->config, 'demo.password');
        } else {
            $identifier = data_get($this->config, 'username');
            $password = data_get($this->config, 'password');
        }

        if (empty($identifier) || empty($password)) {
            return null;
        }

        return [
            'identifier' => $identifier,
            'password' => $password,
            'encryptedPassword' => false,
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
