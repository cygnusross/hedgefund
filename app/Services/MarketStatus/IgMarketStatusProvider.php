<?php

namespace App\Services\MarketStatus;

use App\Domain\Market\Contracts\MarketStatusProvider;
use App\Models\Market;
use App\Services\IG\Endpoints\MarketsEndpoint;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class IgMarketStatusProvider implements MarketStatusProvider
{
    public function __construct(
        private MarketsEndpoint $marketsEndpoint,
        private ?CacheRepository $cache = null,
    ) {}

    public function fetch(string $pair, \DateTimeImmutable $nowUtc, array $opts = []): array
    {
        $market = Market::where('symbol', $pair)->first();
        $epic = $market && $market->epic ? $market->epic : strtoupper(str_replace('/', '-', $pair));

        $cacheKey = 'ig:marketStatus:'.$epic;
        $marketIdCacheKey = 'ig:marketId:'.$epic;

        $status = $this->getFromCache($cacheKey);
        $cachedMarketId = $this->getFromCache($marketIdCacheKey);
        $quoteAge = null;

        if ($status === null) {
            try {
                $resp = $this->marketsEndpoint->get($epic);
                $snapshot = $resp['snapshot'] ?? [];
                $statusRaw = $snapshot['marketStatus'] ?? ($resp['marketStatus'] ?? ($resp['tradingStatus'] ?? ($resp['marketState'] ?? null)));

                $updateTs = $snapshot['updateTimestampUTC'] ?? $snapshot['updateTimestamp'] ?? null;
                if (is_string($updateTs) && $updateTs !== '') {
                    try {
                        $updateDt = new \DateTimeImmutable($updateTs, new \DateTimeZone('UTC'));
                        $quoteAge = max(0, $nowUtc->getTimestamp() - $updateDt->getTimestamp());
                    } catch (\Throwable $e) {
                        $quoteAge = null;
                    }
                } else {
                    $delayTime = $snapshot['delayTime'] ?? null;
                    if (is_numeric($delayTime) && $delayTime >= 0) {
                        $quoteAge = (int) $delayTime;
                    }
                }

                $respMarketId = $resp['instrument']['marketId'] ?? null;
                if (is_string($respMarketId) && $respMarketId !== '') {
                    $cachedMarketId = $respMarketId;
                    $this->putInCache($marketIdCacheKey, $cachedMarketId, 24 * 60 * 60);
                }

                if (is_string($statusRaw) && $statusRaw !== '') {
                    $status = $statusRaw;
                } else {
                    $status = 'UNKNOWN';
                }

                $this->putInCache($cacheKey, $status, 30);
            } catch (\Throwable $e) {
                Log::warning('ig_market_status_unavailable', ['pair' => $pair, 'error' => $e->getMessage()]);
                $status = 'UNKNOWN';
            }
        }

        return [
            'status' => $status,
            'quote_age_sec' => $quoteAge,
            'market_id' => $cachedMarketId,
        ];
    }

    private function getFromCache(string $key)
    {
        try {
            return $this->cache ? $this->cache->get($key) : Cache::get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function putInCache(string $key, $value, int $ttl): void
    {
        try {
            if ($this->cache) {
                $this->cache->put($key, $value, $ttl);
            } else {
                Cache::put($key, $value, $ttl);
            }
        } catch (\Throwable $e) {
            // ignore cache write failures
        }
    }
}
