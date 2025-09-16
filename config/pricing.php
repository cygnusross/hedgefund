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

    'ig' => [
        'timeout' => env('IG_TIMEOUT', 10),
        // IG uses epic format like CS.D.EURUSD.MINI.IP instead of symbols
        'symbol_map' => [
            'EUR/USD' => 'CS.D.EURUSD.MINI.IP',
            'GBP/USD' => 'CS.D.GBPUSD.MINI.IP',
            'USD/JPY' => 'CS.D.USDJPY.MINI.IP',
            'AUD/USD' => 'CS.D.AUDUSD.MINI.IP',
            'USD/CAD' => 'CS.D.USDCAD.MINI.IP',
            'USD/CHF' => 'CS.D.USDCHF.MINI.IP',
            'EUR/GBP' => 'CS.D.EURGBP.MINI.IP',
            'EUR/JPY' => 'CS.D.EURJPY.MINI.IP',
            'GBP/JPY' => 'CS.D.GBPJPY.MINI.IP',
        ],
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
