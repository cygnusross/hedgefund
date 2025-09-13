<?php

namespace App\Http\Controllers;

use App\Infrastructure\Prices\CandleCacheContract;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Http\JsonResponse;

final class CandleHealthController
{
    public function __invoke(string $pair, CandleCacheContract $cache): JsonResponse
    {
        $five = $cache->tailTs($pair, '5min');
        $thirty = $cache->tailTs($pair, '30min');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $fiveAge = $five ? ($now->getTimestamp() - $five->getTimestamp()) : null;

        $status = 'ok';
        if ($fiveAge === null || $fiveAge > 360) { // older than ~6 minutes
            $status = 'stale';
        }

        return response()->json([
            'pair' => $pair,
            '5m_tail' => $five?->format('c'),
            '30m_tail' => $thirty?->format('c'),
            'now_utc' => $now->format('c'),
            'status' => $status,
        ]);
    }
}
