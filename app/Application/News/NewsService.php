<?php

namespace App\Application\News;

use App\Models\NewsStat;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class NewsService implements NewsServiceInterface
{
    public function __construct(
        private ?CacheRepository $cache = null
    ) {
        // Use injected cache repository for testing, fallback to facade
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * {@inheritdoc}
     */
    public function getNews(string $pair, string $date): NewsData
    {
        // Normalize date parameter
        $normalizedDate = $this->normalizeDate($date);

        // Try cache first
        $cacheKey = NewsCacheKeyStrategy::statKey($pair, $normalizedDate);
        try {
            $cachedData = $this->cache->get($cacheKey);
        } catch (\Exception $e) {
            // Cache error - proceed to database
            $cachedData = null;
        }

        if ($cachedData !== null) {
            return NewsData::fromPayload($cachedData, $pair, $normalizedDate);
        }

        // Cache miss - try database
        $pairNorm = $this->normalizePair($pair);
        $newsStat = NewsStat::where('pair_norm', $pairNorm)
            ->whereDate('stat_date', $normalizedDate)
            ->first();

        if ($newsStat) {
            // Rebuild cache from database with 12-hour TTL (720 minutes)
            $cacheData = [
                'raw_score' => $newsStat->raw_score,
                'strength' => $newsStat->strength,
                'counts' => [
                    'pos' => $newsStat->pos,
                    'neg' => $newsStat->neg,
                    'neu' => $newsStat->neu,
                ],
                'pair' => $pair,
                'date' => $normalizedDate,
                'source' => $newsStat->source,
            ];

            $this->cache->put($cacheKey, $cacheData, 720); // 12 hours in minutes

            return NewsData::fromModel($newsStat);
        }

        // No data found - return neutral
        return NewsData::neutral($pair, $normalizedDate);
    }

    /**
     * Convert date parameter to Y-m-d format
     */
    private function normalizeDate(string $date): string
    {
        if ($date === 'today') {
            return now('UTC')->format('Y-m-d');
        }

        return $date;
    }

    /**
     * Convert pair to database format (EUR/USD -> EUR-USD)
     */
    private function normalizePair(string $pair): string
    {
        return strtoupper(str_replace(['/', ' '], '-', $pair));
    }
}
