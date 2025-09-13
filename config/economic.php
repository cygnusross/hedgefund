<?php

return [
    'calendar_url' => env('ECONOMIC_CALENDAR_URL', 'https://nfs.faireconomy.media/ff_calendar_thisweek.json'),
    // cache TTL in seconds
    'cache_ttl' => env('ECONOMIC_CALENDAR_TTL', 1800), // 30 minutes by default
];
