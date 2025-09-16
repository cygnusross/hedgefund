# News JSON Caching

This document describes the news data caching system implemented for ForexNewsApi responses to improve performance and reduce API call frequency.

## Overview

The news caching system provides transparent JSON response caching for all news ingestion operations while preserving the existing database persistence functionality. It reduces API calls to ForexNewsApi and improves response times for frequently requested news data.

## Architecture

### Components

-   **NewsCacheKeyStrategy**: Handles cache key generation, TTL management, and normalization
-   **NewsStatIngestor**: Enhanced with caching layer using Laravel's Cache facade
-   **ForexNewsApiProvider**: Remains unchanged - caching is transparent

### Cache Key Format

Cache keys follow a consistent pattern:

```
news_json:{type}:{pair}:{date}[:{page}]
```

#### Examples

-   `news_json:stat:EUR-USD:2024-01-15` - Single pair/date stat request
-   `news_json:page:EUR-USD,GBP-USD:today:1` - Paginated request for multiple pairs
-   `news_json:range:USD-JPY:01152024-01202024` - Date range request

#### Key Components

-   **Type**: `stat`, `page`, or `range`
-   **Pair**: Normalized currency pair (e.g., `EUR-USD`, `GBP-USD`)
-   **Date**: Normalized date (`YYYY-MM-DD` or special values like `today`)
-   **Page**: Page number for paginated requests

## TTL (Time To Live) Strategy

### Dynamic TTL

-   **Today's data**: 4 hours (14,400 seconds) for freshness
-   **Historical data**: 24 hours (86,400 seconds) for efficiency
-   **Range data**: 24 hours (historical data assumption)

### TTL Methods

```php
// Get appropriate TTL for a date
$ttl = NewsCacheKeyStrategy::getTtl('today'); // 14400
$ttl = NewsCacheKeyStrategy::getTtl('2024-01-15'); // 86400

// Check if date represents today
$isToday = NewsCacheKeyStrategy::isToday('today'); // true
$isToday = NewsCacheKeyStrategy::isToday('2024-01-15'); // false
```

## Usage

### Automatic Caching (Transparent)

All existing `NewsStatIngestor` methods now include automatic caching:

```php
$ingestor = new NewsStatIngestor($forexNewsApiProvider);

// First call hits API, caches response, persists to database
$stat = $ingestor->ingest('EUR/USD', 'today');

// Second call uses cached response, still persists to database
$stat = $ingestor->ingest('EUR/USD', 'today');

// Paginated ingestion with caching
$count = $ingestor->ingestToday(['EUR/USD', 'GBP/USD']);
```

### Direct Cache Access (No Database Persistence)

For API consumers that only need the raw JSON without database storage:

```php
// Get cached JSON for single pair
$cached = $ingestor->getCachedNewsJson('EUR/USD', 'today');
if ($cached) {
    $rawScore = $cached['raw_score'];
    $counts = $cached['counts'];
}

// Get cached JSON for multiple pairs
$batchCached = $ingestor->getCachedTodayJson(['EUR/USD', 'GBP/USD']);
foreach ($batchCached as $pair => $data) {
    // Process cached data without hitting API or database
}
```

### Cache Management

```php
// Manually invalidate specific cache entry
$success = $ingestor->invalidateCache('EUR/USD', 'today');

// Clear all caches for a pair (today, yesterday, etc.)
$ingestor->clearPairCache('EUR/USD');
```

## Logging

Cache operations are logged at the `debug` level for monitoring:

```php
// Cache miss (API call made)
Log::debug('News cache miss, fetching from API', [
    'cache_key' => 'news_json:stat:EUR-USD:2024-01-15',
    'pair' => 'EUR-USD',
    'date' => '2024-01-15'
]);

// Cache hit (API call avoided)
Log::debug('News cache hit', [
    'cache_key' => 'news_json:stat:EUR-USD:2024-01-15'
]);

// Manual invalidation
Log::info('Manually invalidated news cache', [
    'cache_key' => 'news_json:stat:EUR-USD:2024-01-15',
    'pair' => 'EUR-USD',
    'date' => '2024-01-15',
    'success' => true
]);
```

## Configuration

No additional configuration is required. The system uses Laravel's default cache driver and configuration.

### Environment Considerations

-   **Local Development**: Uses file cache by default
-   **Production**: Configure Redis or Memcached for optimal performance
-   **Testing**: Cache is automatically flushed between tests

## Performance Benefits

### API Call Reduction

-   **Before**: Every ingestion operation hits ForexNewsApi
-   **After**: Repeated requests within TTL window use cached responses
-   **Savings**: Up to 100% API call reduction for frequently requested pairs/dates

### Response Time Improvement

-   **Cached responses**: ~1-5ms
-   **API responses**: ~200-500ms
-   **Improvement**: 40-500x faster for cached data

### Example Scenarios

1. **Repeated batch processing**: Multiple commands requesting same date's data
2. **Dashboard refreshes**: UI components fetching same news data
3. **Development/testing**: Rapid iteration without API limits

## Testing

Comprehensive test coverage is provided in `tests/Feature/News/NewsStatIngestorCacheTest.php`:

```bash
# Run cache-specific tests
php artisan test tests/Feature/News/NewsStatIngestorCacheTest.php

# Run all news tests
php artisan test tests/Feature/News/
```

### Test Coverage

-   ✅ Cache key generation and normalization
-   ✅ TTL handling for today vs historical data
-   ✅ API call reduction verification
-   ✅ Cache hit/miss behavior
-   ✅ Direct cache retrieval methods
-   ✅ Cache invalidation functionality
-   ✅ Database persistence alongside caching

## Troubleshooting

### Common Issues

1. **Cache not working**: Check Laravel cache configuration and driver
2. **Stale data**: Verify TTL settings and consider manual invalidation
3. **Memory usage**: Monitor cache size in production, consider Redis

### Debug Commands

```bash
# Check cache status
php artisan tinker
> Cache::get('news_json:stat:EUR-USD:2024-01-15');

# Clear all caches
php artisan cache:clear

# Monitor cache hits in logs
tail -f storage/logs/laravel.log | grep "News cache"
```

### Performance Monitoring

Monitor cache effectiveness through logs:

-   High cache hit ratios indicate effective caching
-   Frequent cache misses may suggest TTL adjustments needed
-   Log volume can indicate API call frequency reduction

## Future Enhancements

### Potential Improvements

1. **Cache tags**: For bulk invalidation by pair or date
2. **Cache warming**: Pre-populate cache for active pairs
3. **Distributed caching**: Cross-instance cache sharing
4. **Metrics collection**: Cache hit/miss ratio tracking
5. **Smart TTL**: Dynamic TTL based on market hours

### Configuration Options

Consider adding configuration options for:

-   Custom TTL values per data type
-   Cache key prefixes for multi-tenant setups
-   Cache driver selection per environment
-   Automatic cache warming schedules

---

_Documentation updated for news JSON caching implementation - January 2024_
