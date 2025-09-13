<?php

return [
    'default' => env('NEWS_PROVIDER', 'forexnewsapi'),

    'forexnewsapi' => [
        'base_url' => env('FOREXNEWSAPI_BASE_URL', 'https://forexnewsapi.com/api/v1'),
        'token' => env('FOREXNEWSAPI_TOKEN'),
    ],
];
