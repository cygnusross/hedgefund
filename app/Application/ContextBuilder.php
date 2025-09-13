<?php

namespace App\Application;

use App\Application\Calendar\CalendarLookup;
use App\Application\Candles\CandleUpdaterContract;
use App\Application\News\NewsAggregator;
use App\Domain\Decision\DecisionContext;
use App\Domain\Features\FeatureEngine;
use App\Domain\FX\PipMath;
use App\Domain\FX\SpreadEstimator;
use App\Services\IG\ClientSentimentProvider;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Support\Facades\Log;

final class ContextBuilder
{
    // Make SpreadEstimator optional to preserve backward compatibility for tests that
    // construct ContextBuilder with only three arguments. When null, spread estimation
    // will be skipped.
    public function __construct(public CandleUpdaterContract $updater, public NewsAggregator $news, public CalendarLookup $calendar, public ?SpreadEstimator $spreadEstimator = null, public ?ClientSentimentProvider $sentimentProvider = null) {}

    /**
     * Build a DecisionContext for the given pair at timestamp $ts.
     * Returns null if not enough warm-up data.
     */
    // Add $forceSpread to allow callers (like CLI) to bypass estimator cache when requested.
    public function build(string $pair, \DateTimeImmutable $ts, mixed $newsDateOrDays = null, bool $fresh = false, bool $forceSpread = false): ?array
    {
        // Resolve bootstrap limits from config (safe keys)
        $limit5 = (int) (config('pricing.bootstrap_limit_5min') ?? config('pricing.bootstrap_limit') ?? 500);
        $limit30 = (int) (config('pricing.bootstrap_limit_30min') ?? config('pricing.bootstrap_limit') ?? 500);

        $bars5 = $this->updater->sync($pair, '5min', $limit5);
        $bars30 = $this->updater->sync($pair, '30min', $limit30);

        // Ensure oldest->newest
        usort($bars5, fn ($a, $b) => $a->ts <=> $b->ts);
        usort($bars30, fn ($a, $b) => $a->ts <=> $b->ts);

        // Cut both to ts <= $ts (no look-ahead)
        $bars5 = array_values(array_filter($bars5, fn ($b) => $b->ts <= $ts));
        $bars30 = array_values(array_filter($bars30, fn ($b) => $b->ts <= $ts));

        $featureSet = FeatureEngine::buildAt($bars5, $bars30, $ts, $pair);
        if ($featureSet === null) {
            return null;
        }

        // Config-driven values (allow caller to override via $newsDateOrDays)
        $newsDays = is_int($newsDateOrDays) ? $newsDateOrDays : (int) config('decision.news_days', 1);
        $blackoutMinutes = (int) config('decision.blackout_minutes_high', 60);
        // Determine date parameter for stats: allow explicit string override via $newsDateOrDays
        if (is_string($newsDateOrDays) && $newsDateOrDays !== '') {
            $dateParam = $newsDateOrDays;
        } else {
            // default 'today'; if $ts is before today UTC, use the ts date
            $todayUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $todayDate = $todayUtc->format('Y-m-d');
            $tsDate = $ts->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $dateParam = $tsDate < $todayDate ? $tsDate : 'today';
        }

        // Freshness: method param overrides config, but config can enable by default
        $fresh = $fresh || (bool) config('decision.news_fresh', false);

        // Attach news summary (stats-only) and keep raw provenance fields for DecisionContext
        $newsSummary = $this->news->summary($pair, $dateParam, $fresh);
        if (! is_array($newsSummary)) {
            $newsSummary = [];
        }

        // Debug: record what date param was and what provider returned (helps tests be deterministic)
        Log::debug('ContextBuilder: news summary', [
            'date_param' => $dateParam,
            'news_summary' => $newsSummary,
        ]);

        // Attach calendar info
        $cal = $this->calendar->summary($pair, $ts);

        // Read blackout window from config (kept in meta only)
        $blkMin = (int) config('decision.blackout_minutes_high', 60);

        // Compute withinBlackout based on next high minutes_to and configured window
        $minutesTo = null;
        if (is_array($cal) && isset($cal['next_high']['minutes_to'])) {
            $minutesTo = (int) $cal['next_high']['minutes_to'];
        }
        $withinBlackout = $minutesTo !== null ? ($minutesTo <= $blkMin) : false;
        $blackout = (bool) $withinBlackout;

        // Log debug line with pair, ts, next_high_impact_minutes, blackout
        try {
            $logTs = $ts->format(\DateTimeImmutable::ATOM);
        } catch (\Throwable $e) {
            $logTs = (string) $ts;
        }
        $nextMinutes = null;
        if (is_array($cal) && isset($cal['next_high']['minutes_to'])) {
            $nextMinutes = $cal['next_high']['minutes_to'];
        }

        Log::debug('ContextBuilder: preview', [
            'pair' => $pair,
            'ts' => $logTs,
            'next_high_impact_minutes' => $nextMinutes,
            'blackout' => $blackout,
        ]);

        // Compute tails and freshness provenance
        $tail5 = count($bars5) ? end($bars5) : null;
        $tail30 = count($bars30) ? end($bars30) : null;
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dataAgeSec = null;
        if ($tail5 instanceof \App\Domain\Market\Bar) {
            $dataAgeSec = max(0, $nowUtc->getTimestamp() - $tail5->ts->getTimestamp());
        }

        $bars5meta = ['count' => count($bars5), 'tail_ts_utc' => $tail5 ? $tail5->ts->format(DATE_ATOM) : null];
        $bars30meta = ['count' => count($bars30), 'tail_ts_utc' => $tail30 ? $tail30->ts->format(DATE_ATOM) : null];

        // Market extras
        $lastPrice = $tail5 instanceof \App\Domain\Market\Bar ? (float) $tail5->close : null;
        // Convert atr5m to pips using helper that handles JPY pairs
        $atr5m = $featureSet->atr5m ?? null;
        $atr5mPips = is_numeric($atr5m) ? round(PipMath::toPips((float) $atr5m, $pair), 1) : null;

        // Seconds until next 5-minute boundary
        $nowTs = $nowUtc->getTimestamp();
        $mod = $nowTs % 300;
        $nextBarEta = $mod === 0 ? 0 : (300 - $mod);

        $marketMeta = [
            'last_price' => $lastPrice,
            'atr5m_pips' => $atr5mPips,
            'next_bar_eta_sec' => (int) $nextBarEta,
        ];

        // Live spread estimate from IG (may return null)
        $spread = null;
        $marketStatus = null;
        if ($this->spreadEstimator !== null) {
            try {
                $spread = $this->spreadEstimator->estimatePipsForPair($pair, $forceSpread);
                $marketStatus = $this->spreadEstimator->getMarketStatusForPair($pair, $forceSpread);
            } catch (\Throwable $e) {
                Log::warning('ig_spread_unavailable', ['reason' => 'contextbuilder_call_failed', 'pair' => $pair, 'error' => $e->getMessage()]);
                $spread = null;
            }
        }

        // Always expose the key so callers can observe when the estimator returned null.
        $marketMeta['spread_estimate_pips'] = $spread;
        $marketMeta['status'] = $marketStatus;

        // Attach client sentiment if available via provider (may be null)
        $sentiment = null;
        try {
            if ($this->sentimentProvider !== null) {
                // attempt to derive marketId/epic from pair string if possible
                $marketId = strtoupper(str_replace('/', '-', $pair));
                $sentiment = $this->sentimentProvider->fetch($marketId);
            }
        } catch (\Throwable $e) {
            Log::warning('client_sentiment_unavailable', ['pair' => $pair, 'error' => $e->getMessage()]);
            $sentiment = null;
        }

        $marketMeta['sentiment'] = $sentiment;

        // Remove blackout duplication from calendar payload if provider added it
        if (is_array($cal) && isset($cal['blackout_minutes_high'])) {
            unset($cal['blackout_minutes_high']);
        }

        // Create DecisionContext and pass meta via constructor (non-breaking addition)
        $ctx = new DecisionContext($pair, $ts, $featureSet, [
            'schema_version' => '1.0.0',
            'pair_norm' => strtoupper(str_replace('/', '-', $pair)),
            'data_age_sec' => $dataAgeSec,
            'bars_5m' => $bars5meta,
            'bars_30m' => $bars30meta,
            'calendar_blackout_window_min' => $blkMin,
        ]);

        // Build payload by merging ctx->toArray() with news/calendar/blackout
        $payload = $ctx->toArray();
        // Keep news direction/strength/counts as before but also include raw fields
        $payload['news'] = [
            'direction' => $newsSummary['direction'] ?? 'neutral',
            'strength' => $newsSummary['strength'] ?? 0.0,
            'counts' => $newsSummary['counts'] ?? ['pos' => 0, 'neg' => 0, 'neu' => 0],
            'raw_score' => $newsSummary['raw_score'] ?? null,
            'date' => $newsSummary['date'] ?? null,
        ];
        $payload['calendar'] = $cal;
        $payload['blackout'] = $blackout;
        // Expose market as a top-level block for cleaner schema
        $payload['market'] = $marketMeta;

        return $payload;
    }

    // ATR-to-pips conversion is centralized in App\Domain\FX\PipMath
}
