<?php

namespace App\Services\IG;

use App\Services\IG\Endpoints\ClientSentimentEndpoint;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

class ClientSentimentProvider
{
    public function __construct(protected ClientSentimentEndpoint $endpoint, protected CacheRepository $cache) {}

    /**
     * Fetch client sentiment for the given marketId.
     * Returns ['long_pct'=>float,'short_pct'=>float,'as_of'=>string] or null when values are missing.
     */
    public function fetch(string $marketId, bool $force = false): ?array
    {
        $key = $this->cacheKey($marketId);

        if ($force) {
            try {
                $this->cache->forget($key);
            } catch (\Throwable $e) {
                // ignore cache driver failures
            }
        } else {
            try {
                $cached = $this->cache->get($key);
            } catch (\Throwable $e) {
                $cached = null;
            }

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $resp = $this->endpoint->get($marketId);
        } catch (\Throwable $e) {
            Log::warning('ClientSentimentProvider: endpoint error: '.$e->getMessage());

            return null;
        }

        if (! is_array($resp) || empty($resp)) {
            return null;
        }

        // Primary expected keys per IG: longPositionPercentage, shortPositionPercentage
        $long = null;
        $short = null;

        if (isset($resp['longPositionPercentage'])) {
            $long = (float) $resp['longPositionPercentage'];
        }

        if (isset($resp['shortPositionPercentage'])) {
            $short = (float) $resp['shortPositionPercentage'];
        }

        // Accept some alternate keys if the API returns different shapes
        if ($long === null && isset($resp['longPercent'])) {
            $long = (float) $resp['longPercent'];
        }

        if ($short === null && isset($resp['shortPercent'])) {
            $short = (float) $resp['shortPercent'];
        }

        if ($long === null && isset($resp['long_pct'])) {
            $long = (float) $resp['long_pct'];
        }

        if ($short === null && isset($resp['short_pct'])) {
            $short = (float) $resp['short_pct'];
        }

        if ($long === null || $short === null) {
            return null;
        }

        $out = [
            'long_pct' => $long,
            'short_pct' => $short,
            'as_of' => now()->toIso8601String(),
        ];

        try {
            $this->cache->put($key, $out, 60);
        } catch (\Throwable $e) {
            // ignore cache failures
        }

        return $out;
    }

    protected function cacheKey(string $marketId): string
    {
        return 'ig:sentiment:{'.$marketId.'}';
    }
}
