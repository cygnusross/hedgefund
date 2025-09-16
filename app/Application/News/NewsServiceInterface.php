<?php

namespace App\Application\News;

interface NewsServiceInterface
{
    /**
     * Retrieve news data for a specific currency pair and date.
     *
     * Tries cache first, falls back to database if cache miss.
     * Returns neutral data if neither cache nor database has data.
     *
     * @param  string  $pair  Currency pair (e.g., 'EUR/USD')
     * @param  string  $date  Date in Y-m-d format or 'today'
     * @return NewsData Consistent news data structure
     */
    public function getNews(string $pair, string $date): NewsData;
}
