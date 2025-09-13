<?php

return [
    'driver' => env('PRICE_DRIVER', 'twelvedata'),

    'twelvedata' => [
        'base_url' => env('TWELVEDATA_BASE_URL', 'https://api.twelvedata.com'),
        'api_key' => env('TWELVEDATA_API_KEY'),
        'timeout' => env('TWELVEDATA_TIMEOUT', 8),
        // default mapping for intervals (optional)
        'interval_map' => [
            'MINUTE_5' => '5min',
            'MINUTE' => '1min',
            'MINUTE_30' => '30min',
            'HOUR' => '1h',
            'DAY' => '1day',
        ],
    ],
    // Candle cache settings
    'cache_ttl' => env('CANDLE_CACHE_TTL', 3600), // seconds
    'normalize_timestamps' => env('CANDLE_NORMALIZE_TS', false),
    'calendar_blackout_minutes' => env('CALENDAR_BLACKOUT_MINUTES', 60),
];
