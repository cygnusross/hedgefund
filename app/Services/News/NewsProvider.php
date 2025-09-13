<?php

namespace App\Services\News;

/**
 * Stats-only NewsProvider interface.
 * Providers must implement `fetchStat` which returns sentiment aggregates for a pair and date.
 */
interface NewsProvider
{
    /**
     * Fetch aggregated sentiment statistics for a currency pair.
     *
     * @return array{
     *   pair: string,
     *   date: string|null,
     *   pos: int,
     *   neg: int,
     *   neu: int,
     *   score: float,
     * }|array Empty array on failure
     */
    public function fetchStat(string $pair, string $date = 'today', bool $fresh = false): array;
}
