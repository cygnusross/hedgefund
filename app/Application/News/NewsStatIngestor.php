<?php

namespace App\Application\News;

use App\Models\NewsStat;
use App\Services\News\ForexNewsApiProvider;
use Illuminate\Support\Carbon;

class NewsStatIngestor
{
    public function __construct(protected ForexNewsApiProvider $api) {}

    /**
     * Fetch from ForexNewsApi (no cache) and persist a single stat row.
     * Returns the saved NewsStat or null on failure.
     */
    public function ingest(string $pair, string $date = 'today'): ?NewsStat
    {
        $pair_norm = strtoupper(str_replace(['/', ' '], '-', $pair));

        // Call provider live; provider uses $noCache param so pass $fresh as true => no cache
        $resp = $this->api->fetchStats($pair_norm, $date);

        // If provider returned no array or empty response, upsert a neutral
        // row but store NULL in the payload to indicate no provenance.
        if (! is_array($resp) || empty($resp)) {
            $raw = 0.0;
            $str = 0.0;
            $pos = 0;
            $neg = 0;
            $neu = 0;

            $day = ($date === 'today') ? Carbon::now('UTC')->format('Y-m-d') : $date;

            return NewsStat::upsertFromApi([
                'pair_norm' => $pair_norm,
                'stat_date' => $day,
                'pos' => $pos,
                'neg' => $neg,
                'neu' => $neu,
                'raw_score' => $raw,
                'strength' => $str,
                'source' => 'forexnewsapi',
                'fetched_at' => Carbon::now('UTC'),
                'payload' => null,
            ]);
        }

        $raw = (float) ($resp['raw_score'] ?? 0);
        $str = $raw / 1.5;
        $pos = (int) ($resp['counts']['pos'] ?? 0);
        $neg = (int) ($resp['counts']['neg'] ?? 0);
        $neu = (int) ($resp['counts']['neu'] ?? 0);

        $day = ($date === 'today') ? Carbon::now('UTC')->format('Y-m-d') : $date;

        // If provider returned no meaningful data (all counts zero and raw score 0),
        // store a null payload to indicate no provenance was available.
        $payload = $resp;
        if ((($pos ?? 0) === 0) && (($neg ?? 0) === 0) && (($neu ?? 0) === 0) && ($raw === 0.0)) {
            $payload = null;
        }

        return NewsStat::upsertFromApi([
            'pair_norm' => $pair_norm,
            'stat_date' => $day,
            'pos' => $pos,
            'neg' => $neg,
            'neu' => $neu,
            'raw_score' => $raw,
            'strength' => $str,
            'source' => 'forexnewsapi',
            'fetched_at' => Carbon::now('UTC'),
            'payload' => $payload,
        ]);
    }

    /**
     * Ingest a range (e.g. 'last30days') and upsert each date bucket.
     * Returns the number of rows upserted.
     */
    public function ingestRange(string $pair, string $range = 'last30days'): int
    {
        $pair_norm = strtoupper(str_replace(['/', ' '], '-', $pair));

        $resp = $this->api->fetchStats($pair_norm, $range);
        if (! is_array($resp) || empty($resp)) {
            return 0;
        }

        $count = 0;

        // The provider returns shape with 'data' => [ 'YYYY-MM-DD' => [ 'PAIR' => [counts..., sentiment_score] ] ]
        // Or a totals object when date is a single date. For ranges we expect 'data'.
        $data = $resp['data'] ?? null;
        if (! is_array($data)) {
            // If provider returned totals-style for the pair, try to extract single entry
            // Fallback: Map the top-level response as a single-day stat
            $raw = (float) ($resp['raw_score'] ?? 0);
            $str = $raw / 1.5;
            $pos = (int) ($resp['counts']['pos'] ?? 0);
            $neg = (int) ($resp['counts']['neg'] ?? 0);
            $neu = (int) ($resp['counts']['neu'] ?? 0);
            $day = ($range === 'today') ? Carbon::now('UTC')->format('Y-m-d') : ($resp['date'] ?? Carbon::now('UTC')->format('Y-m-d'));
            // If the caller requested an explicit MMDDYYYY-MMDDYYYY range, the
            // provider might return totals instead of per-day buckets. In that
            // case, fall back to fetching each day individually so we upsert one
            // row per day as the user likely expects.
            if (preg_match('/^(\d{6,8})-(\d{6,8})$/', $range, $m)) {
                try {
                    // Parse MMDDYYYY into Carbon dates
                    $start = Carbon::createFromFormat('mdY', $m[1]);
                    $end = Carbon::createFromFormat('mdY', $m[2]);
                } catch (\Throwable $e) {
                    // If parsing fails, fall back to single totals upsert
                    NewsStat::upsertFromApi([
                        'pair_norm' => $pair_norm,
                        'stat_date' => $day,
                        'pos' => $pos,
                        'neg' => $neg,
                        'neu' => $neu,
                        'raw_score' => $raw,
                        'strength' => $str,
                        'source' => 'forexnewsapi',
                        'fetched_at' => Carbon::now('UTC'),
                        'payload' => $resp,
                    ]);

                    return 1;
                }

                // Ensure start <= end
                if ($start->gt($end)) {
                    [$start, $end] = [$end, $start];
                }

                // Use the batched range ingest which will call the provider with
                // a single date range and handle pagination. This avoids making
                // one API call per day.
                $fromYmd = $start->format('Y-m-d');
                $toYmd = $end->format('Y-m-d');

                return $this->ingestRangeDates($pair, $fromYmd, $toYmd);
            }

            NewsStat::upsertFromApi([
                'pair_norm' => $pair_norm,
                'stat_date' => $day,
                'pos' => $pos,
                'neg' => $neg,
                'neu' => $neu,
                'raw_score' => $raw,
                'strength' => $str,
                'source' => 'forexnewsapi',
                'fetched_at' => Carbon::now('UTC'),
                'payload' => $resp,
            ]);

            return 1;
        }

        foreach ($data as $dayKey => $buckets) {
            if (! is_array($buckets)) {
                continue;
            }

            $pairData = $buckets[$pair_norm] ?? ($buckets[strtoupper($pair_norm)] ?? null);
            if (! is_array($pairData)) {
                continue;
            }

            $pos = (int) ($pairData['Positive'] ?? $pairData['pos'] ?? 0);
            $neg = (int) ($pairData['Negative'] ?? $pairData['neg'] ?? 0);
            $neu = (int) ($pairData['Neutral'] ?? $pairData['neutral'] ?? 0);
            $raw = isset($pairData['sentiment_score']) ? (float) $pairData['sentiment_score'] : (float) ($pairData['Sentiment Score'] ?? 0.0);
            $str = $raw / 1.5;

            NewsStat::upsertFromApi([
                'pair_norm' => $pair_norm,
                'stat_date' => $dayKey,
                'pos' => $pos,
                'neg' => $neg,
                'neu' => $neu,
                'raw_score' => $raw,
                'strength' => $str,
                'source' => 'forexnewsapi',
                'fetched_at' => Carbon::now('UTC'),
                'payload' => $pairData,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Ingest today's pages using the provider's paging API.
     * Returns number of rows upserted.
     */
    /**
     * Ingest today's pages for one or more pairs. $pair may be a string or
     * an array of pair symbols. Returns number of rows upserted.
     */
    public function ingestToday(string|array $pair): int
    {
        $pairs = is_array($pair) ? $pair : [$pair];
        $pair_norms = array_map(fn ($p) => strtoupper(str_replace(['/', ' '], '-', $p)), $pairs);
        $dateParam = 'today';

        // First page to discover total_pages
        $page = 1;
        $totalUpserts = 0;

        do {
            // Make a single call for all pairs (comma-separated)
            $pairQuery = implode(',', $pair_norms);
            $raw = $this->api->fetchStatPage($pairQuery, $dateParam, $page);
            if (! is_array($raw) || empty($raw)) {
                $page++;
                // small polite pause
                usleep(100000);

                continue;
            }

            $totalPages = isset($raw['total_pages']) ? (int) $raw['total_pages'] : 1;

            $data = $raw['data'] ?? [];
            if (is_array($data)) {
                foreach ($data as $dayKey => $buckets) {
                    if (! is_array($buckets)) {
                        continue;
                    }

                    foreach ($pair_norms as $pair_norm) {
                        $pairData = $buckets[$pair_norm] ?? ($buckets[strtoupper($pair_norm)] ?? null);
                        if (! is_array($pairData)) {
                            continue;
                        }

                        $pos = (int) ($pairData['Positive'] ?? $pairData['pos'] ?? 0);
                        $neg = (int) ($pairData['Negative'] ?? $pairData['neg'] ?? 0);
                        $neu = (int) ($pairData['Neutral'] ?? $pairData['neutral'] ?? 0);
                        $rawScore = isset($pairData['sentiment_score']) ? (float) $pairData['sentiment_score'] : (float) ($pairData['Sentiment Score'] ?? 0.0);

                        // Normalize rawScore (-1.5..1.5) to strength 0..1
                        $strength = max(0.0, min(1.0, ($rawScore + 1.5) / 3.0));

                        $normalizedPayload = null;
                        if (! ($pos === 0 && $neg === 0 && $neu === 0 && $rawScore === 0.0)) {
                            $normalizedPayload = [
                                'pair' => $pair_norm,
                                'raw_score' => $rawScore,
                                'strength' => $strength,
                                'counts' => ['pos' => $pos, 'neg' => $neg, 'neu' => $neu],
                                'date' => $dayKey,
                            ];
                        }

                        NewsStat::upsertFromApi([
                            'pair_norm' => $pair_norm,
                            'stat_date' => $dayKey,
                            'pos' => $pos,
                            'neg' => $neg,
                            'neu' => $neu,
                            'raw_score' => $rawScore,
                            'strength' => $strength,
                            'source' => 'forexnewsapi',
                            'fetched_at' => Carbon::now('UTC'),
                            'payload' => $normalizedPayload,
                        ]);

                        $totalUpserts++;
                    }
                }
            }

            $page++;
            // polite pause between pages
            usleep(100000);
        } while ($page <= ($totalPages ?? 1));

        return $totalUpserts;
    }

    /**
     * Ingest an explicit MMDDYYYY-MMDDYYYY range. Accepts from/to as Y-m-d
     * and converts to mdY for the provider.
     */
    /**
     * Ingest an explicit MMDDYYYY-MMDDYYYY range. Accepts from/to as Y-m-d
     * and converts to mdY for the provider. $pair may be string or array.
     */
    public function ingestRangeDates(string|array $pair, string $fromYmd, string $toYmd): int
    {
        $pairs = is_array($pair) ? $pair : [$pair];
        $pair_norms = array_map(fn ($p) => strtoupper(str_replace(['/', ' '], '-', $p)), $pairs);

        $from = Carbon::parse($fromYmd, 'UTC')->format('mdY');
        $to = Carbon::parse($toYmd, 'UTC')->format('mdY');

        $dateParam = $from.'-'.$to;

        $page = 1;
        $totalUpserts = 0;
        do {
            $pairQuery = implode(',', $pair_norms);
            $raw = $this->api->fetchStatPage($pairQuery, $dateParam, $page);
            if (! is_array($raw) || empty($raw)) {
                $page++;
                usleep(100000);

                continue;
            }

            $totalPages = isset($raw['total_pages']) ? (int) $raw['total_pages'] : 1;

            $data = $raw['data'] ?? [];
            if (is_array($data)) {
                foreach ($data as $dayKey => $buckets) {
                    if (! is_array($buckets)) {
                        continue;
                    }

                    foreach ($pair_norms as $pair_norm) {
                        $pairData = $buckets[$pair_norm] ?? ($buckets[strtoupper($pair_norm)] ?? null);
                        if (! is_array($pairData)) {
                            continue;
                        }

                        $pos = (int) ($pairData['Positive'] ?? $pairData['pos'] ?? 0);
                        $neg = (int) ($pairData['Negative'] ?? $pairData['neg'] ?? 0);
                        $neu = (int) ($pairData['Neutral'] ?? $pairData['neutral'] ?? 0);
                        $rawScore = isset($pairData['sentiment_score']) ? (float) $pairData['sentiment_score'] : (float) ($pairData['Sentiment Score'] ?? 0.0);

                        $strength = max(0.0, min(1.0, ($rawScore + 1.5) / 3.0));

                        $normalizedPayload = null;
                        if (! ($pos === 0 && $neg === 0 && $neu === 0 && $rawScore === 0.0)) {
                            $normalizedPayload = [
                                'pair' => $pair_norm,
                                'raw_score' => $rawScore,
                                'strength' => $strength,
                                'counts' => ['pos' => $pos, 'neg' => $neg, 'neu' => $neu],
                                'date' => $dayKey,
                            ];
                        }

                        NewsStat::upsertFromApi([
                            'pair_norm' => $pair_norm,
                            'stat_date' => $dayKey,
                            'pos' => $pos,
                            'neg' => $neg,
                            'neu' => $neu,
                            'raw_score' => $rawScore,
                            'strength' => $strength,
                            'source' => 'forexnewsapi',
                            'fetched_at' => Carbon::now('UTC'),
                            'payload' => $normalizedPayload,
                        ]);

                        $totalUpserts++;
                    }
                }
            }

            $page++;
            usleep(100000);
        } while ($page <= ($totalPages ?? 1));

        return $totalUpserts;
    }
}
