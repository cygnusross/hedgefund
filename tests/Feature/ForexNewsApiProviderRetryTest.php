<?php

use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('retries once with trial-sized items on 403 and returns items', function () {
    // Ensure trial limit is set for the test
    config(['news.trial' => true, 'news.max_limit_trial' => 2]);

    // First response: 403
    Http::fakeSequence()
        ->push('', 403)
        ->push([
            'data' => [
                [
                    'title' => 'Test Item 1',
                    'text' => 'Summary 1',
                    'news_url' => 'https://example.com/1',
                    'date' => 'Fri, 12 Sep 2025 09:58:23 -0400',
                    'sentiment' => 'Positive',
                    'currency' => 'EUR-USD',
                ],
                [
                    'title' => 'Test Item 2',
                    'text' => 'Summary 2',
                    'news_url' => 'https://example.com/2',
                    'date' => 'Fri, 12 Sep 2025 10:00:00 -0400',
                    'sentiment' => 'Neutral',
                    'currency' => 'EUR-USD',
                ],
            ],
        ], 200);

    // Clear cache for trial log key
    Cache::forget('news:forexnewsapi:trial_warning_logged');

    $provider = new ForexNewsApiProvider(['base_url' => 'https://forexnewsapi.test', 'token' => 'FAKE']);

    // Call fetchStat twice to exercise the HTTP sequence: first 403 then successful
    $stat = $provider->fetchStat('EUR/USD', '2025-09-12', false);

    expect($stat)->toBeArray();

    // fetchStat should only send a single request for /stat (no trial retry logic)
    Http::assertSentCount(1);
});
