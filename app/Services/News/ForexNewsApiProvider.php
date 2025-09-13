<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ForexNewsApiProvider implements NewsProvider
{
    public function __construct(protected array $config = [])
    {
        $this->config = array_merge(config('news.forexnewsapi', []), $config);
    }

    /**
     * Fetch aggregated sentiment statistics from the /stat endpoint.
     * Returns compact stats shape or neutral zeros on failure.
     *
     * Signature: fetchStats(string $pair, string $date = 'today', bool $noCache = true): array
     */
    public function fetchStats(string $pair, string $date = 'today', bool $noCache = true): array
    {
        $pairDash = str_replace('/', '-', strtoupper($pair));
        $dateKey = $date; // caller may pass 'today' or 'last30days' or an explicit date

        $base = rtrim($this->config['base_url'] ?? config('news.forexnewsapi.base_url', ''), '/');
        $token = $this->config['token'] ?? config('news.forexnewsapi.token');
        if (! $base || ! $token) {
            Log::warning('ForexNewsApiProvider::fetchStat missing base_url or token in config');

            return [];
        }

        $queryParams = [
            'currencypair' => $pairDash,
            'date' => $dateKey,
            'page' => 1,
            'token' => $token,
        ];
        if ($noCache) {
            $queryParams['cache'] = 'false';
        }

        $query = http_build_query($queryParams);

        $url = $base.'/stat?'.$query;

        try {
            $resp = Http::timeout(5)->retry(1, 100)->get($url);
        } catch (\Throwable $e) {
            Log::warning('ForexNewsApiProvider::fetchStat HTTP request failed: '.$e->getMessage());

            return $this->neutralFor($pairDash, $dateKey);
        }

        if (! $resp->successful()) {
            Log::warning(sprintf('ForexNewsApiProvider::fetchStat HTTP error %d when fetching %s', $resp->status(), $url));

            return $this->neutralFor($pairDash, $dateKey);
        }

        try {
            $json = $resp->json();
        } catch (\Throwable $e) {
            Log::warning('ForexNewsApiProvider::fetchStat failed to parse JSON: '.$e->getMessage());

            return [];
        }

        if (! is_array($json)) {
            return $this->neutralFor($pairDash, $dateKey);
        }

        $data = $json['data'] ?? [];
        $total = $json['total'] ?? [];

        // Prefer totals when available
        if (is_array($total)) {
            $parsed = $this->parseTotals($total, $pairDash, $dateKey);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // Fall back to per-date bucket parsing
        $latestDate = null;
        $bucket = null;
        if (is_array($data) && count($data) > 0) {
            $dates = array_keys($data);
            sort($dates, SORT_STRING);
            $latestDate = end($dates);
            $bucket = $data[$latestDate] ?? null;
        }

        if (is_array($bucket)) {
            $pairData = $bucket[$pairDash] ?? ($bucket[strtoupper($pairDash)] ?? null);
            if (is_array($pairData)) {
                $pos = (int) ($pairData['Positive'] ?? $pairData['pos'] ?? $pairData['Pos'] ?? 0);
                $neg = (int) ($pairData['Negative'] ?? $pairData['neg'] ?? $pairData['Neg'] ?? 0);
                $neu = (int) ($pairData['Neutral'] ?? $pairData['neutral'] ?? $pairData['Neu'] ?? 0);
                $score = isset($pairData['sentiment_score']) ? (float) $pairData['sentiment_score'] : (float) ($pairData['Sentiment Score'] ?? 0.0);

                return $this->formatStats($pairDash, $score, $pos, $neg, $neu, $latestDate ?? $dateKey);
            }
        }

        // final fallback: try totals again
        if (is_array($total)) {
            $parsed = $this->parseTotals($total, $pairDash, $dateKey);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return $this->neutralFor($pairDash, $dateKey);
    }

    // Article fetching removed — this provider is stats-only and implements fetchStat above.

    /**
     * Parse totals array for a given pair if present and return normalized shape or null
     */
    private function parseTotals(array $total, string $pairDash, string $dateKey): ?array
    {
        if (! isset($total[$pairDash]) || ! is_array($total[$pairDash])) {
            return null;
        }

        $t = $total[$pairDash];
        $pos = isset($t['Total Positive']) ? (int) $t['Total Positive'] : (int) ($t['Positive'] ?? 0);
        $neg = isset($t['Total Negative']) ? (int) $t['Total Negative'] : (int) ($t['Negative'] ?? 0);
        $neu = isset($t['Total Neutral']) ? (int) $t['Total Neutral'] : (int) ($t['Neutral'] ?? 0);
        $score = isset($t['Sentiment Score']) ? (float) $t['Sentiment Score'] : (float) ($t['sentiment_score'] ?? 0.0);

        return $this->formatStats($pairDash, $score, $pos, $neg, $neu, $dateKey);
    }

    private function formatStats(string $pairDash, float $score, int $pos, int $neg, int $neu, string $dateKey): array
    {
        $strength = ($score + 1.5) / 3.0;

        return [
            'pair' => $pairDash,
            'raw_score' => $score,
            'strength' => $strength,
            'counts' => ['pos' => $pos, 'neg' => $neg, 'neu' => $neu],
            'date' => $dateKey,
        ];
    }

    private function neutralFor(string $pairDash, string $dateKey): array
    {
        return $this->formatStats($pairDash, 0.0, 0, 0, 0, $dateKey);
    }

    /**
     * Backwards-compatible wrapper for the NewsProvider interface.
     * Returns the older shape: ['pair','date','pos','neg','neu','score']
     */
    public function fetchStat(string $pair, string $date = 'today', bool $fresh = false): array
    {
        $stats = $this->fetchStats($pair, $date, $fresh);

        if (empty($stats) || ! is_array($stats)) {
            return [];
        }

        return [
            'pair' => $stats['pair'] ?? $pair,
            'date' => $stats['date'] ?? $date,
            'pos' => $stats['counts']['pos'] ?? 0,
            'neg' => $stats['counts']['neg'] ?? 0,
            'neu' => $stats['counts']['neu'] ?? 0,
            'score' => $stats['raw_score'] ?? 0.0,
        ];
    }
}
