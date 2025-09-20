<?php

namespace App\Application;

use App\Application\Calendar\CalendarLookup;
use App\Application\Candles\CandleUpdaterContract;
use App\Domain\Decision\DecisionContext;
use App\Domain\Decision\DTO\DecisionMetadata;
use App\Domain\Decision\DTO\RulesSnapshot;
use App\Domain\Features\FeatureEngine;
use App\Domain\FX\Contracts\SpreadEstimatorContract;
use App\Domain\FX\PipMath;
use App\Domain\Market\Contracts\MarketStatusProvider;
use App\Domain\Rules\RuleContextManager;
use App\Domain\Sentiment\Contracts\SentimentProvider;
use App\Models\Account;
use App\Models\Market;
use App\Services\IG\Endpoints\MarketsEndpoint;
use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Support\Facades\Log;

class ContextBuilder
{
    private RuleContextManager $ruleContextManager;

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
        public ?SpreadEstimatorContract $spreadEstimator = null,
        public ?SentimentProvider $sentimentProvider = null,
        public ?MarketsEndpoint $markets = null,
        public ?MarketStatusProvider $marketStatusProvider = null,
        ?RuleContextManager $ruleContextManager = null,
    ) {
        $this->ruleContextManager = $ruleContextManager ?? app(RuleContextManager::class);
    }

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
        $overlap5 = (int) (config('pricing.overlap_bars_5min') ?? 2);
        $overlap30 = (int) (config('pricing.overlap_bars_30min') ?? 2);
        $tail5 = (int) (config('pricing.tail_fetch_limit_5min') ?? 200);
        $tail30 = (int) (config('pricing.tail_fetch_limit_30min') ?? 200);

        $bars5 = $this->updater->sync($pair, '5min', $limit5, $overlap5, $tail5);
        $bars30 = $this->updater->sync($pair, '30min', $limit30, $overlap30, $tail30);

        // Ensure oldest->newest
        usort($bars5, fn ($a, $b) => $a->ts <=> $b->ts);
        usort($bars30, fn ($a, $b) => $a->ts <=> $b->ts);

        // Cut both to ts <= $ts (no look-ahead)
        $bars5 = array_values(array_filter($bars5, fn ($b) => $b->ts <= $ts));
        $bars30 = array_values(array_filter($bars30, fn ($b) => $b->ts <= $ts));

        return ['bars5' => $bars5, 'bars30' => $bars30];
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

            $cacheKey = 'ig:marketStatus:'.$epic;
            $marketIdCacheKey = 'ig:marketId:'.$epic;

            // Prefer injected cache repository when available (easier to test); otherwise use the facade
            $igMarketStatus = $this->getFromCache($cacheKey);
            // Attempt to read a previously cached IG marketId for this epic (24h TTL)
            $cachedMarketId = $this->getFromCache($marketIdCacheKey);

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
                    $this->putInCache($marketIdCacheKey, $cachedMarketId, 24 * 60 * 60);
                }

                if (is_string($status) && $status !== '') {
                    $igMarketStatus = $status;
                } else {
                    $igMarketStatus = 'UNKNOWN';
                }

                try {
                    $this->putInCache($cacheKey, $igMarketStatus, 30);
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
        $atr5mPips = null;
        if (is_numeric($atr5m)) {
            $atrPipsDecimal = Decimal::of(PipMath::toPips((float) $atr5m, $pair))->toScale(1, RoundingMode::HALF_UP);
            $atr5mPips = Decimal::toFloat($atrPipsDecimal, 1);
        }

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
        if ($this->marketStatusProvider !== null) {
            $statusInfo = $this->marketStatusProvider->fetch($pair, $nowUtc, $opts);
            $igMarketStatus = $statusInfo['status'] ?? null;
            $igMarketQuoteAge = $statusInfo['quote_age_sec'] ?? null;
            $cachedMarketId = $statusInfo['market_id'] ?? null;
        } else {
            $statusInfo = $this->fetchIgMarketStatus($pair, $nowUtc);
            $igMarketStatus = $statusInfo['igMarketStatus'];
            $igMarketQuoteAge = $statusInfo['igMarketQuoteAge'];
            $cachedMarketId = $statusInfo['cachedMarketId'];
        }

        if ($igMarketStatus === null) {
            $igMarketStatus = 'UNKNOWN';
        }

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

        if ($market !== null) {
            $gateOverrides = [];
            $overrideFields = [
                'atr_min_pips' => $market->atr_min_pips_override,
                'adx_min' => $market->adx_min_override,
                'z_abs_max' => $market->z_abs_max_override,
            ];

            foreach ($overrideFields as $gateKey => $value) {
                if ($value !== null) {
                    $gateOverrides[$gateKey] = (float) $value;
                }
            }

            if (! empty($gateOverrides)) {
                $marketMeta['gate_overrides'] = $gateOverrides;
            }
        }

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
    public function build(string $pair, \DateTimeImmutable $ts, bool $fresh = false, bool $forceSpread = false, array $opts = [], ?string $accountName = null): ?array
    {
        $marketBars = $this->getMarketBars($pair, $ts);
        $bars5 = $marketBars['bars5'];
        $bars30 = $marketBars['bars30'];

        $featureSet = FeatureEngine::buildAt($bars5, $bars30, $ts, $pair);
        if ($featureSet === null) {
            return null;
        }

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

        $rulesSnapshot = null;
        try {
            $ruleContext = $this->ruleContextManager->current();
            if ($ruleContext !== null) {
                $layeredRules = $ruleContext->forMarket($pair);
                $rulesSnapshot = RulesSnapshot::fromArray([
                    'layered' => $layeredRules,
                    'tag' => $ruleContext->tag,
                    'metadata' => $ruleContext->metadata,
                ]);

                if ($ruleContext->tag !== null) {
                    $marketMeta['rule_set_tag'] = $ruleContext->tag;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('contextbuilder_rules_snapshot_failed', [
                'pair' => $pair,
                'error' => $e->getMessage(),
            ]);
        }

        // Remove blackout duplication from calendar payload if provider added it
        if (is_array($cal) && isset($cal['blackout_minutes_high'])) {
            unset($cal['blackout_minutes_high']);
        }

        $accountInfo = $this->selectAccount($accountName, $pair);
        $account = $accountInfo['account'];
        $sleeveBalance = $accountInfo['sleeveBalance'];

        // Create DecisionContext and pass meta via constructor (non-breaking addition)
        $ctx = new DecisionContext(
            $pair,
            $ts,
            $featureSet,
            new DecisionMetadata([
                'schema_version' => '1.0.0',
                'pair_norm' => strtoupper(str_replace('/', '-', $pair)),
                'data_age_sec' => $dataAgeSec,
                'bars_5m' => $bars5meta,
                'bars_30m' => $bars30meta,
                'calendar_blackout_window_min' => $blkMin,
                'sleeve_balance' => $sleeveBalance,
                'account_name' => $account?->name,
                'account_id' => $account?->id,
                'rule_set_tag' => $rulesSnapshot?->tag(),
            ]), $rulesSnapshot);

        // Build payload by merging ctx->toArray() with calendar/blackout metadata
        $payload = $ctx->toArray();
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

    private function getFromCache(string $key)
    {
        try {
            return \Illuminate\Support\Facades\Cache::get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function putInCache(string $key, $value, int $ttl): void
    {
        try {
            \Illuminate\Support\Facades\Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            // ignore cache write failures
        }
    }
}
