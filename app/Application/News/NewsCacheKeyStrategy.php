<?php

namespace App\Application\News;

use Illuminate\Support\Carbon;

class NewsCacheKeyStrategy
{
    /**
     * Cache key prefix for all news JSON data
     */
    private const PREFIX = 'news_json';

    /**
     * Default TTL for cached news data (24 hours in seconds)
     */
    public const DEFAULT_TTL = 86400;

    /**
     * Generate cache key for single pair/date stat request
     * Format: news_json:stat:EUR-USD:2024-01-15
     */
    public static function statKey(string $pair, string $date): string
    {
        $pairNorm = strtoupper(str_replace(['/', ' '], '-', $pair));
        $dateNorm = self::normalizeDateParam($date);

        return self::PREFIX.':stat:'.$pairNorm.':'.$dateNorm;
    }

    /**
     * Generate cache key for paginated stat request
     * Format: news_json:page:EUR-USD,GBP-USD:today:1
     */
    public static function pageKey(string $pairQuery, string $dateParam, int $page): string
    {
        $pairNorm = strtoupper(str_replace(['/', ' '], '-', $pairQuery));
        $dateNorm = self::normalizeDateParam($dateParam);

        return self::PREFIX.':page:'.$pairNorm.':'.$dateNorm.':'.$page;
    }

    /**
     * Generate cache key for range requests
     * Format: news_json:range:EUR-USD:01152024-01202024
     */
    public static function rangeKey(string $pair, string $fromYmd, string $toYmd): string
    {
        $pairNorm = strtoupper(str_replace(['/', ' '], '-', $pair));
        $from = Carbon::parse($fromYmd)->format('mdY');
        $to = Carbon::parse($toYmd)->format('mdY');

        return self::PREFIX.':range:'.$pairNorm.':'.$from.'-'.$to;
    }

    /**
     * Get cache tags for invalidation by pair or date
     */
    public static function tagsForPair(string $pair): array
    {
        $pairNorm = strtoupper(str_replace(['/', ' '], '-', $pair));

        return ['news_pair:'.$pairNorm];
    }

    public static function tagsForDate(string $date): array
    {
        $dateNorm = self::normalizeDateParam($date);

        return ['news_date:'.$dateNorm];
    }

    /**
     * Normalize date parameter for consistent cache keys
     */
    private static function normalizeDateParam(string $date): string
    {
        if ($date === 'today') {
            return Carbon::now('UTC')->format('Y-m-d');
        }

        if ($date === 'last30days') {
            return 'last30days';
        }

        // Handle MMDDYYYY-MMDDYYYY range format
        if (preg_match('/^(\d{6,8})-(\d{6,8})$/', $date)) {
            return $date; // Keep as-is for range queries
        }

        // Try to parse as Y-m-d format
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return $date; // Fallback to original if parsing fails
        }
    }

    /**
     * Get TTL based on date parameter
     * Today's data expires faster, historical data can be cached longer
     */
    public static function getTtl(string $date): int
    {
        if ($date === 'today' || $date === Carbon::now('UTC')->format('Y-m-d')) {
            // Today's data expires in 4 hours for freshness
            return 14400;
        }

        // Historical data can be cached for 24 hours
        return self::DEFAULT_TTL;
    }

    /**
     * Check if a date parameter represents current day data
     */
    public static function isToday(string $date): bool
    {
        if ($date === 'today') {
            return true;
        }

        try {
            $parsed = Carbon::parse($date);

            return $parsed->isSameDay(Carbon::now('UTC'));
        } catch (\Throwable $e) {
            return false;
        }
    }
}
