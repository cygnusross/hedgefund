<?php

namespace App\Services\IG;

use App\Services\IG\Endpoints\ClientSentimentEndpoint;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

final class ClientSentimentProvider
{
    public function __construct(protected ClientSentimentEndpoint $endpoint, protected CacheRepository $cache) {}

    /**
     * Fetch client sentiment for the given marketId (epic).
     * Returns ['long_pct'=>float,'short_pct'=>float,'as_of'=>string] or null on failure.
     */
    public function fetch(string $marketId, bool $force = false): ?array
    {
        $key = 'ig:sentiment:' . strtoupper($marketId);

        if (! $force) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $resp = $this->endpoint->get($marketId);
        } catch (\Throwable $e) {
            Log::warning('ClientSentimentProvider: endpoint error: ' . $e->getMessage());

            return null;
        }

        if (! is_array($resp) || empty($resp)) {
            return null;
        }

        // Normalize expected shapes
        $long = null;
        $short = null;
        $asOf = null;

        if (isset($resp['longPercent'])) {
            $long = (float) $resp['longPercent'];
        }

        if (isset($resp['shortPercent'])) {
            $short = (float) $resp['shortPercent'];
        }

        if (isset($resp['long_pct'])) {
            $long = (float) $resp['long_pct'];
        }

        if (isset($resp['short_pct'])) {
            $short = (float) $resp['short_pct'];
        }

        if (isset($resp['long'])) {
            $long = (float) $resp['long'];
        }

        if (isset($resp['short'])) {
            $short = (float) $resp['short'];
        }

        $asOf = $resp['asOf'] ?? ($resp['as_of'] ?? ($resp['timestamp'] ?? null));

        if ($long === null && $short === null) {
            return null;
        }

        $out = [
            'long_pct' => $long ?? 0.0,
            'short_pct' => $short ?? 0.0,
            'as_of' => $asOf,
        ];

        try {
            $this->cache->put($key, $out, 60);
        } catch (\Throwable $e) {
            // ignore cache failures
        }

        return $out;
    }
}
