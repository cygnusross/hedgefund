<?php

namespace App\Application;

use App\Application\Calendar\CalendarLookup;
use App\Application\Candles\CandleUpdaterContract;
use App\Application\News\NewsCacheKeyStrategy;
use App\Application\News\NewsServiceInterface;
use App\Domain\Decision\DecisionContext;
use App\Domain\Features\FeatureEngine;
use App\Domain\FX\PipMath;
use App\Domain\FX\SpreadEstimator;
use App\Models\Account;
use App\Models\Market;
use App\Services\IG\ClientSentimentProvider;
use App\Services\IG\Endpoints\MarketsEndpoint;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Support\Facades\Log;

class ContextBuilder
{
    /**
     * Return true when the provided UTC datetime falls on Saturday or Sunday.
     */
    private function isWeekendUTC(\DateTimeImmutable $dt): bool
    {
        // Ensure timezone is UTC for the weekday check
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $weekday = (int) $utc->format('w'); // 0 (Sun) - 6 (Sat)

        return $weekday === 0 || $weekday === 6;
    }

    // Make SpreadEstimator optional to preserve backward compatibility for tests that
    // construct ContextBuilder with only three arguments. When null, spread estimation
    // will be skipped. Also allow optional injection of IG Markets endpoint and a
    // CacheRepository for easier testing; fall back to the container/facades when
    // these are not provided to preserve backwards compatibility.
    public function __construct(
        public CandleUpdaterContract $updater,
        public CalendarLookup $calendar,
        public ?SpreadEstimator $spreadEstimator = null,
        public ?ClientSentimentProvider $sentimentProvider = null,
        public ?MarketsEndpoint $markets = null,
        public ?CacheRepository $cacheRepo = null,
        public ?NewsServiceInterface $newsService = null
    ) {}

    /**
     * Fetch and prepare market bar data for the given pair and timestamp.
     *
     * @return array{bars5: array, bars30: array}
     */
    private function getMarketBars(string $pair, \DateTimeImmutable $ts): array
    {
        // Resolve bootstrap limits from config (safe keys)
        $limit5 = (int) (config('pricing.bootstrap_limit_5min') ?? config('pricing.bootstrap_limit') ?? 500);
        $limit30 = (int) (config('pricing.bootstrap_limit_30min') ?? config('pricing.bootstrap_limit') ?? 500);

        $bars5 = $this->updater->sync($pair, '5min', $limit5);
        $bars30 = $this->updater->sync($pair, '30min', $limit30);

        // Ensure oldest->newest
        usort($bars5, fn($a, $b) => $a->ts <=> $b->ts);
        usort($bars30, fn($a, $b) => $a->ts <=> $b->ts);

        // Cut both to ts <= $ts (no look-ahead)
        $bars5 = array_values(array_filter($bars5, fn($b) => $b->ts <= $ts));
        $bars30 = array_values(array_filter($bars30, fn($b) => $b->ts <= $ts));

        return ['bars5' => $bars5, 'bars30' => $bars30];
    }

    /**
     * Resolve the news date parameter from various inputs.
     *
     * @return array{dateParam: string, statDate: string}
     */
    private function resolveNewsDate(mixed $newsDateOrDays, \DateTimeImmutable $ts): array
    {
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

        // Resolve date parameter to explicit date for cache lookup
        $statDate = $dateParam === 'today' ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d') : $dateParam;

        return ['dateParam' => $dateParam, 'statDate' => $statDate];
    }

    /**
     * Get news data using the NewsService.
     */
    private function getNewsFromService(string $pair, string $statDate): array
    {
        $newsData = $this->newsService->getNews($pair, $statDate);

        $rawScore = $newsData->rawScore;
        $direction = $rawScore > 0 ? 'buy' : ($rawScore < 0 ? 'sell' : 'neutral');

        return [
            'direction' => $direction,
            'strength' => $newsData->strength,
            'counts' => $newsData->counts,
            'raw_score' => $rawScore,
            'date' => $newsData->date,
        ];
    }

    /**
     * Get news data using fallback cache/database logic.
     */
    private function getNewsFromCache(string $pair, string $statDate): array
    {
        $cacheKey = NewsCacheKeyStrategy::statKey($pair, $statDate);
        $newsData = Cache::get($cacheKey);

        if ($newsData !== null && isset($newsData['raw_score'])) {
            // Process cached API response data to match expected context format
            $rawScore = (float) ($newsData['raw_score'] ?? 0.0);
            $direction = $rawScore > 0 ? 'buy' : ($rawScore < 0 ? 'sell' : 'neutral');

            return [
                'direction' => $direction,
                'strength' => (float) ($newsData['strength'] ?? 0.0),
                'counts' => $newsData['counts'] ?? ['pos' => 0, 'neg' => 0, 'neu' => 0],
                'raw_score' => $rawScore,
                'date' => $statDate,
            ];
        }

        // Cache miss - try to rebuild from database
        return $this->rebuildNewsFromDatabase($pair, $statDate, $cacheKey);
    }

    /**
     * Rebuild news data from database and update cache.
     */
    private function rebuildNewsFromDatabase(string $pair, string $statDate, string $cacheKey): array
    {
        $pairNorm = strtoupper(str_replace(['/', ' '], '-', $pair));
        $newsStat = \App\Models\NewsStat::where('pair_norm', $pairNorm)
            ->whereDate('stat_date', $statDate)
            ->first();

        if ($newsStat) {
            // Read data from payload if available, otherwise from model fields
            $newsData = $newsStat->payload ?: [];

            // Get raw_score from payload or model field
            $rawScore = (float) ($newsData['raw_score'] ?? $newsStat->raw_score ?? 0.0);

            // Get strength from payload or model field
            $strength = (float) ($newsData['strength'] ?? $newsStat->strength ?? 0.0);

            // Get counts from payload or build from model fields
            $counts = $newsData['counts'] ?? [
                'pos' => $newsStat->pos ?? 0,
                'neg' => $newsStat->neg ?? 0,
                'neu' => $newsStat->neu ?? 0,
            ];

            // Rebuild cache from combined data
            $cacheData = [
                'raw_score' => $rawScore,
                'strength' => $strength,
                'counts' => $counts,
            ];
            $ttl = NewsCacheKeyStrategy::getTtl($statDate);
            Cache::put($cacheKey, $cacheData, $ttl);

            $direction = $rawScore > 0 ? 'buy' : ($rawScore < 0 ? 'sell' : 'neutral');

            return [
                'direction' => $direction,
                'strength' => $strength,
                'counts' => $counts,
                'raw_score' => $rawScore,
                'date' => $statDate,
            ];
        }

        // No cached data or database record available - use neutral defaults
        return [
            'direction' => 'neutral',
            'strength' => 0.0,
            'counts' => ['pos' => 0, 'neg' => 0, 'neu' => 0],
            'raw_score' => 0.0,
            'date' => $statDate,
        ];
    }

    /**
     * Retrieve news data using NewsService if available, otherwise fallback to cache/database.
     */
    private function getNewsData(string $pair, string $statDate): array
    {
        if ($this->newsService !== null) {
            return $this->getNewsFromService($pair, $statDate);
        }

        return $this->getNewsFromCache($pair, $statDate);
    }

    /**
     * Process calendar data and compute blackout information.
     *
     * @return array{cal: mixed, blackout: bool, blkMin: int}
     */
    private function processCalendarData(string $pair, \DateTimeImmutable $ts): array
    {
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

        return ['cal' => $cal, 'blackout' => $blackout, 'blkMin' => $blkMin];
    }

    /**
     * Select appropriate account and calculate available balance for position sizing.
     *
     * @return array{account: ?Account, sleeveBalance: float}
     */
    private function selectAccount(?string $accountName, string $pair): array
    {
        $account = null;
        if ($accountName !== null) {
            $account = Account::where('name', $accountName)->where('is_active', true)->first();
        }

        // Fallback to default active trading account if no specific account requested
        if ($account === null) {
            $account = Account::active()->trading()->first();
        }

        // If still no account found, log warning and use hardcoded fallback
        $sleeveBalance = 10000.0; // default fallback
        if ($account !== null) {
            $sleeveBalance = (float) $account->calculateAvailableBalance();
        } else {
            Log::warning('No active trading account found, using hardcoded balance', [
                'pair' => $pair,
                'requested_account' => $accountName,
                'fallback_balance' => $sleeveBalance,
            ]);
        }

        return ['account' => $account, 'sleeveBalance' => $sleeveBalance];
    }

    /**
     * Fetch IG market status and quote age information.
     *
     * @return array{igMarketStatus: ?string, igMarketQuoteAge: ?int, cachedMarketId: ?string}
     */
    private function fetchIgMarketStatus(string $pair, \DateTimeImmutable $nowUtc): array
    {
        $igMarketStatus = null;
        $igMarketQuoteAge = null;
        $cachedMarketId = null;

        try {
            $market = Market::where('symbol', $pair)->first();
            $epic = $market && $market->epic ? $market->epic : strtoupper(str_replace('/', '-', $pair));

            $cacheKey = 'ig:marketStatus:' . $epic;
            $marketIdCacheKey = 'ig:marketId:' . $epic;

            // Prefer injected cache repository when available (easier to test); otherwise use the facade
            $igMarketStatus = $this->cacheRepo ? $this->cacheRepo->get($cacheKey) : Cache::get($cacheKey);
            // Attempt to read a previously cached IG marketId for this epic (24h TTL)
            $cachedMarketId = $this->cacheRepo ? $this->cacheRepo->get($marketIdCacheKey) : Cache::get($marketIdCacheKey);

            if ($igMarketStatus === null) {
                // Prefer injected markets endpoint when provided for testability
                $me = $this->markets ?? app(MarketsEndpoint::class);
                $resp = $me->get($epic);
                $status = $resp['snapshot']['marketStatus'] ?? null;

                // compute quote_age_sec if updateTimestampUTC is present
                $updateTs = $resp['snapshot']['updateTimestampUTC'] ?? $resp['snapshot']['updateTimestamp'] ?? null;
                if (is_string($updateTs) && $updateTs !== '') {
                    try {
                        $updateDt = new \DateTimeImmutable($updateTs, new \DateTimeZone('UTC'));
                        $igMarketQuoteAge = max(0, $nowUtc->getTimestamp() - $updateDt->getTimestamp());
                    } catch (\Throwable $e) {
                        // leave null on parse errors
                        $igMarketQuoteAge = null;
                    }
                } else {
                    // Fallback: use delayTime if available (represents data delay in seconds)
                    $delayTime = $resp['snapshot']['delayTime'] ?? null;
                    if (is_numeric($delayTime) && $delayTime >= 0) {
                        $igMarketQuoteAge = (int) $delayTime;
                    }
                }

                // Get marketId from the correct location (instrument.marketId, not snapshot.marketId)
                $respMarketId = $resp['instrument']['marketId'] ?? null;
                if (is_string($respMarketId) && $respMarketId !== '') {
                    $cachedMarketId = $respMarketId;
                    try {
                        if ($this->cacheRepo) {
                            $this->cacheRepo->put($marketIdCacheKey, $cachedMarketId, 24 * 60 * 60);
                        } else {
                            Cache::put($marketIdCacheKey, $cachedMarketId, 24 * 60 * 60);
                        }
                    } catch (\Throwable $e) {
                        // non-fatal - proceed without caching
                    }
                }

                if (is_string($status) && $status !== '') {
                    $igMarketStatus = $status;
                } else {
                    $igMarketStatus = 'UNKNOWN';
                }

                try {
                    if ($this->cacheRepo) {
                        $this->cacheRepo->put($cacheKey, $igMarketStatus, 30);
                    } else {
                        Cache::put($cacheKey, $igMarketStatus, 30);
                    }
                } catch (\Throwable $e) {
                    // ignore cache failures
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ig_market_status_unavailable', ['pair' => $pair, 'error' => $e->getMessage()]);
            $igMarketStatus = 'UNKNOWN';
        }

        return [
            'igMarketStatus' => $igMarketStatus,
            'igMarketQuoteAge' => $igMarketQuoteAge,
            'cachedMarketId' => $cachedMarketId,
        ];
    }

    /**
     * Fetch client sentiment data if available.
     */
    private function fetchSentimentData(string $pair, ?string $cachedMarketId, ?Market $market, array $opts): ?array
    {
        if ($this->sentimentProvider === null) {
            return null;
        }

        try {
            // Prefer the IG marketId resolved from the markets endpoint (if cached),
            // otherwise fall back to the market epic or a derived token from the pair.
            $marketIdToUse = $cachedMarketId ?? ($market && $market->epic ? $market->epic : strtoupper(str_replace('/', '-', $pair)));
            $forceSentiment = (bool) ($opts['force_sentiment'] ?? false);

            return $this->sentimentProvider->fetch($marketIdToUse, $forceSentiment);
        } catch (\Throwable $e) {
            Log::warning('client_sentiment_unavailable', ['pair' => $pair, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build comprehensive market metadata including spread, status, and timing information.
     *
     * @return array{market: array, bars5meta: array, bars30meta: array, dataAgeSec: ?int}
     */
    private function buildMarketMetadata(string $pair, array $bars5, array $bars30, $featureSet, bool $forceSpread, \DateTimeImmutable $nowUtc, array $opts): array
    {
        // Compute tails and freshness provenance
        $tail5 = count($bars5) ? end($bars5) : null;
        $tail30 = count($bars30) ? end($bars30) : null;
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

        // Start with basic market metadata
        $marketMeta = [
            'last_price' => $lastPrice,
            'atr5m_pips' => $atr5mPips,
            'next_bar_eta_sec' => null,
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

        // Fetch IG market status and quote age
        $igInfo = $this->fetchIgMarketStatus($pair, $nowUtc);
        $igMarketStatus = $igInfo['igMarketStatus'];
        $igMarketQuoteAge = $igInfo['igMarketQuoteAge'];
        $cachedMarketId = $igInfo['cachedMarketId'];

        // Expose the canonical IG market id when we resolved one (may be null)
        $marketMeta['market_id'] = is_string($cachedMarketId) ? $cachedMarketId : null;

        // Prefer IG market status when it provides a meaningful value; otherwise fall back to spread estimator result or UNKNOWN
        if ($igMarketStatus !== null && $igMarketStatus !== 'UNKNOWN') {
            $marketMeta['status'] = $igMarketStatus;
        } else {
            // If IG didn't provide a meaningful status, prefer the estimator status when available
            $marketMeta['status'] = $marketMeta['status'] ?? 'UNKNOWN';
            // Weekend fallback: if it's weekend UTC and we have no meaningful IG status, mark as CLOSED
            $nowUtcForWeekend = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($marketMeta['status'] === 'UNKNOWN' && $this->isWeekendUTC($nowUtcForWeekend)) {
                $marketMeta['status'] = 'CLOSED';
                // Ensure we don't provide ETA when market closed
                $marketMeta['next_bar_eta_sec'] = null;
            }
        }

        // Attach quote_age_sec if we computed it from the MarketsEndpoint response; otherwise null
        $marketMeta['quote_age_sec'] = is_int($igMarketQuoteAge) ? $igMarketQuoteAge : null;

        // Compute next_bar_eta_sec only when market status indicates tradeable/deal editing allowed
        // and data age is fresh enough. Use rules.gates.max_data_age_sec (default 600) via AlphaRules.
        $rules = app(\App\Domain\Rules\AlphaRules::class);
        $maxDataAge = (int) $rules->getGate('max_data_age_sec', 600);
        $maxQuoteAge = (int) $rules->getGate('max_quote_age_sec', 300);
        $metaStale = $dataAgeSec !== null ? ($dataAgeSec > $maxDataAge) : true;

        if (! in_array($marketMeta['status'], ['TRADEABLE', 'DEAL_NO_EDIT'], true)) {
            $marketMeta['next_bar_eta_sec'] = null;
        } else {
            if ($dataAgeSec === null || $dataAgeSec > $maxDataAge) {
                $marketMeta['next_bar_eta_sec'] = null;
            } else {
                $nowTs = $nowUtc->getTimestamp();
                $mod = $nowTs % 300;
                $nextBarEta = $mod === 0 ? 0 : (300 - $mod);
                $marketMeta['next_bar_eta_sec'] = (int) $nextBarEta;
            }
        }

        // Add market-level stale flag for callers to check if data is too old
        $marketMeta['stale'] = $metaStale;

        // Gate spread exposure by market status: if market is not TRADEABLE, hide spread and add reason
        $tradeableStatuses = ['TRADEABLE', 'DEAL_NO_EDIT', 'OPEN'];
        if (! in_array($marketMeta['status'], $tradeableStatuses, true)) {
            $marketMeta['spread_estimate_pips'] = null;
            $marketMeta['spread_reason'] = 'market_closed_or_unknown';
        } else {
            // Ensure spread_reason absent when tradeable
            if (isset($marketMeta['spread_reason'])) {
                unset($marketMeta['spread_reason']);
            }
        }

        // Attach client sentiment if available via provider (may be null)
        $market = Market::where('symbol', $pair)->first();
        $sentiment = $this->fetchSentimentData($pair, $cachedMarketId, $market, $opts);

        if ($sentiment !== null) {
            $marketMeta['sentiment'] = $sentiment;
        }

        return [
            'marketMeta' => $marketMeta,
            'maxDataAge' => $maxDataAge,
            'maxQuoteAge' => $maxQuoteAge,
            'bars5meta' => $bars5meta,
            'bars30meta' => $bars30meta,
            'dataAgeSec' => $dataAgeSec,
        ];
    }

    /**
     * Build a DecisionContext for the given pair at timestamp $ts.
     * Returns null if not enough warm-up data.
     */
    // Add $forceSpread to allow callers (like CLI) to bypass estimator cache when requested.
    // Add $accountName to allow selecting which account/sleeve to use for position sizing
    public function build(string $pair, \DateTimeImmutable $ts, mixed $newsDateOrDays = null, bool $fresh = false, bool $forceSpread = false, array $opts = [], ?string $accountName = null): ?array
    {
        $marketBars = $this->getMarketBars($pair, $ts);
        $bars5 = $marketBars['bars5'];
        $bars30 = $marketBars['bars30'];

        $featureSet = FeatureEngine::buildAt($bars5, $bars30, $ts, $pair);
        if ($featureSet === null) {
            return null;
        }

        $dateInfo = $this->resolveNewsDate($newsDateOrDays, $ts);
        $dateParam = $dateInfo['dateParam'];
        $statDate = $dateInfo['statDate'];

        $newsSummary = $this->getNewsData($pair, $statDate);

        // record what date param was and what provider returned (helps tests be deterministic)

        $calendarInfo = $this->processCalendarData($pair, $ts);
        $cal = $calendarInfo['cal'];
        $blackout = $calendarInfo['blackout'];
        $blkMin = $calendarInfo['blkMin'];

        // preview metadata logged for debugging during development (removed in production)

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

        // Build comprehensive market metadata
        $marketMetadata = $this->buildMarketMetadata(
            $pair,
            $bars5,
            $bars30,
            $featureSet,
            $forceSpread,
            $nowUtc,
            $opts
        );
        $marketMeta = $marketMetadata['marketMeta'];
        $maxDataAge = $marketMetadata['maxDataAge'];
        $maxQuoteAge = $marketMetadata['maxQuoteAge'];

        // Remove blackout duplication from calendar payload if provider added it
        if (is_array($cal) && isset($cal['blackout_minutes_high'])) {
            unset($cal['blackout_minutes_high']);
        }

        $accountInfo = $this->selectAccount($accountName, $pair);
        $account = $accountInfo['account'];
        $sleeveBalance = $accountInfo['sleeveBalance'];

        // Create DecisionContext and pass meta via constructor (non-breaking addition)
        $ctx = new DecisionContext($pair, $ts, $featureSet, [
            'schema_version' => '1.0.0',
            'pair_norm' => strtoupper(str_replace('/', '-', $pair)),
            'data_age_sec' => $dataAgeSec,
            'bars_5m' => $bars5meta,
            'bars_30m' => $bars30meta,
            'calendar_blackout_window_min' => $blkMin,
            'sleeve_balance' => $sleeveBalance,
            'account_name' => $account?->name,
            'account_id' => $account?->id,
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
        // Expose freshness thresholds used to compute stale/fresh logic
        $payload['meta']['freshness'] = [
            'max_data_age_sec' => $maxDataAge,
            'max_quote_age_sec' => $maxQuoteAge,
        ];
        // Expose market as a top-level block for cleaner schema
        $payload['market'] = $marketMeta;

        return $payload;
    }

    // ATR-to-pips conversion is centralized in App\Domain\FX\PipMath
}
