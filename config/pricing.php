<?php

return [
    'driver' => env('PRICE_DRIVER', 'ig'),

    'bootstrap_limit_5min' => env('PRICING_BOOTSTRAP_5M', 150),
    'bootstrap_limit_30min' => env('PRICING_BOOTSTRAP_30M', 30),
    'tail_fetch_limit_5min' => env('PRICING_TAIL_5M', 20),
    'tail_fetch_limit_30min' => env('PRICING_TAIL_30M', 12),
    'overlap_bars_5min' => env('PRICING_OVERLAP_5M', 3),
    'overlap_bars_30min' => env('PRICING_OVERLAP_30M', 2),

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

    'ig' => [
        'timeout' => env('IG_TIMEOUT', 10),
        'resolution_map' => [
            '1min' => 'MINUTE',
            '5min' => 'MINUTE_5',
            '15min' => 'MINUTE_15',
            '30min' => 'MINUTE_30',
            '1h' => 'HOUR',
            '2h' => 'HOUR_2',
            '3h' => 'HOUR_3',
            '4h' => 'HOUR_4',
            '1day' => 'DAY',
            '1week' => 'WEEK',
            '1month' => 'MONTH',
        ],
    ],
    // Candle cache settings
    'cache_ttl' => env('CANDLE_CACHE_TTL', 3600), // seconds
    'normalize_timestamps' => env('CANDLE_NORMALIZE_TS', false),
    'calendar_blackout_minutes' => env('CALENDAR_BLACKOUT_MINUTES', 60),
];
