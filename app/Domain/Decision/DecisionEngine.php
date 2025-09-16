<?php

namespace App\Domain\Decision;

use App\Domain\Execution\PositionLedgerContract;
use App\Domain\Rules\AlphaRules;

final class DecisionEngine
{
    public function decide(mixed $context, AlphaRules $rules): array
    {
        $reasons = [];

        // Normalize context into array
        if ($context instanceof DecisionContext) {
            $ctx = $context->toArray();
        } elseif (is_array($context)) {
            $ctx = $context;
        } elseif (is_object($context) && method_exists($context, 'toArray')) {
            $ctx = $context->toArray();
        } else {
            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => ['invalid_context'], 'blocked' => true];
        }

        // Basic safety gates
        $requiredStatuses = $rules->getGate('market_required_status', ['TRADEABLE']);
        if (! is_array($requiredStatuses)) {
            $requiredStatuses = [$requiredStatuses];
        }
        $marketStatus = $ctx['market']['status'] ?? null;
        if ($marketStatus === null || ! in_array($marketStatus, $requiredStatuses, true)) {
            $reasons[] = 'status_closed';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        $dataAge = $ctx['meta']['data_age_sec'] ?? null;
        $maxDataAge = (int) $rules->getGate('max_data_age_sec', 600);
        if ($dataAge === null || $dataAge > $maxDataAge) {
            $reason = $dataAge === null ? 'no_bar_data' : 'bar_data_stale';
            $reasons[] = $reason;

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        if (($ctx['calendar']['within_blackout'] ?? $ctx['blackout'] ?? false) === true) {
            $reasons[] = 'blackout';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Session filtering - avoid trading during suboptimal periods
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (! $this->isOptimalTradingSession($now, $rules)) {
            $reasons[] = 'suboptimal_session';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Spread requirement: if rules demand a spread estimate and it's missing -> block
        $spread = data_get($ctx, 'market.spread_estimate_pips');
        $spreadRequired = (bool) $rules->getGate('spread_required', false);
        if ($spreadRequired && $spread === null) {
            $reasons[] = 'no_spread';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Volatility / stretch gates (ADX and EMA-Z) - only enforce when configured in rules
        $adxRaw = $rules->getGate('adx_min', null);
        $zRaw = $rules->getGate('z_abs_max', null);

        $adx = data_get($ctx, 'features.adx5m');
        $z = data_get($ctx, 'features.ema20_z');

        if ($adxRaw !== null) {
            $adxMin = (int) $adxRaw;
            if ($adx === null || (float) $adx < $adxMin) {
                $reasons[] = 'low_adx';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        if ($zRaw !== null) {
            $zAbsMax = (float) $zRaw;
            if ($z !== null && abs((float) $z) > $zAbsMax) {
                $reasons[] = 'stretched_z';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // New indicator-based gates
        $rsi = data_get($ctx, 'features.rsi14');
        $stochK = data_get($ctx, 'features.stoch_k');
        $williamsR = data_get($ctx, 'features.williamsR');
        $cci = data_get($ctx, 'features.cci');
        $parabolicSAR = data_get($ctx, 'features.parabolicSAR');
        $parabolicSARTrend = data_get($ctx, 'features.parabolicSARTrend');
        $bbUpper = data_get($ctx, 'features.bb_upper');
        $bbLower = data_get($ctx, 'features.bb_lower');
        $trUpper = data_get($ctx, 'features.tr_upper');
        $trLower = data_get($ctx, 'features.tr_lower');
        $lastPrice = data_get($ctx, 'market.last_price');

        // RSI overbought/oversold filter
        $rsiOverbought = (float) $rules->getGate('rsi_overbought', 75);
        $rsiOversold = (float) $rules->getGate('rsi_oversold', 25);

        if ($rsi !== null) {
            if ((float) $rsi > $rsiOverbought) {
                $reasons[] = 'rsi_overbought';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
            if ((float) $rsi < $rsiOversold) {
                $reasons[] = 'rsi_oversold';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // Stochastic extreme filter (avoid trades when extremely overbought/oversold)
        $stochExtreme = (float) $rules->getGate('stoch_extreme', 95);
        if ($stochK !== null && ((float) $stochK > $stochExtreme || (float) $stochK < (100 - $stochExtreme))) {
            $reasons[] = 'stoch_extreme';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Williams %R extreme filter (more sensitive than Stochastic)
        // %R ranges from -100 to 0: -20 to 0 = overbought, -100 to -80 = oversold
        $williamsROverbought = (float) $rules->getGate('williams_r_overbought', -20);
        $williamsROversold = (float) $rules->getGate('williams_r_oversold', -80);

        if ($williamsR !== null) {
            if ((float) $williamsR > $williamsROverbought) {
                $reasons[] = 'williams_r_overbought';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
            if ((float) $williamsR < $williamsROversold) {
                $reasons[] = 'williams_r_oversold';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // CCI extreme filter (cyclical momentum extremes)
        // CCI > +100 = strong overbought, CCI < -100 = strong oversold
        $cciOverbought = (float) $rules->getGate('cci_overbought', 100);
        $cciOversold = (float) $rules->getGate('cci_oversold', -100);

        if ($cci !== null) {
            if ((float) $cci > $cciOverbought) {
                $reasons[] = 'cci_overbought';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
            if ((float) $cci < $cciOversold) {
                $reasons[] = 'cci_oversold';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // Parabolic SAR trend confirmation filter
        $requireSarTrendAlignment = (bool) $rules->getGate('require_sar_trend_alignment', false);

        if ($requireSarTrendAlignment && $parabolicSARTrend !== null && $lastPrice !== null && $parabolicSAR !== null) {
            $newsDirection = data_get($ctx, 'news.direction');

            // Check if news direction conflicts with SAR trend
            if ($newsDirection === 'buy' && $parabolicSARTrend === 'down') {
                $reasons[] = 'sar_trend_conflict';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
            if ($newsDirection === 'sell' && $parabolicSARTrend === 'up') {
                $reasons[] = 'sar_trend_conflict';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // True Range Bands breakout filter (volatility-based momentum)
        $requireTrBreakout = (bool) $rules->getGate('require_tr_breakout', false);

        if ($requireTrBreakout && $trUpper !== null && $trLower !== null && $lastPrice !== null) {
            $newsDirection = data_get($ctx, 'news.direction');

            // For buy signals, require price to be breaking above upper TR band (strong momentum)
            if ($newsDirection === 'buy' && $lastPrice <= $trUpper) {
                $reasons[] = 'insufficient_tr_breakout';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }

            // For sell signals, require price to be breaking below lower TR band (strong momentum)
            if ($newsDirection === 'sell' && $lastPrice >= $trLower) {
                $reasons[] = 'insufficient_tr_breakout';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // Daily stop & cooldown gates
        $dailyStop = (float) $rules->getGate('daily_loss_stop_pct', 3.0);
        $coolAfterLoss = (int) $rules->getCooldown('after_loss_minutes', 20);
        $coolAfterWin = (int) $rules->getCooldown('after_win_minutes', 5);

        // Resolve a PositionLedger from container (allow tests to bind a fake).
        // Fall back to NullPositionLedger when the container doesn't have a binding
        try {
            if (app()->bound(PositionLedgerContract::class)) {
                $ledger = app()->make(PositionLedgerContract::class);
            } else {
                $ledger = new \App\Domain\Execution\NullPositionLedger;
            }
        } catch (\Throwable $e) {
            $ledger = new \App\Domain\Execution\NullPositionLedger;
        }

        try {
            $todaysPnL = (float) $ledger->todaysPnLPct();
        } catch (\Throwable $e) {
            $todaysPnL = 0.0;
        }

        if ($todaysPnL <= -1.0 * $dailyStop) {
            $reasons[] = 'daily_loss_stop';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Cooldown based on last trade outcome
        try {
            $last = $ledger->lastTrade();
        } catch (\Throwable $e) {
            $last = null;
        }

        if (is_array($last) && isset($last['outcome']) && isset($last['ts']) && $last['ts'] instanceof \DateTimeImmutable) {
            $when = $last['ts'];
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $minutesAgo = max(0, (int) ceil(($now->getTimestamp() - $when->getTimestamp()) / 60));

            if ($last['outcome'] === 'loss' && $minutesAgo <= $coolAfterLoss) {
                $reasons[] = 'cooldown_active';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }

            if ($last['outcome'] === 'win' && $minutesAgo <= $coolAfterWin) {
                $reasons[] = 'cooldown_active';

                return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
            }
        }

        // Prepare normalized pair string for exposure checks
        $pairNorm = data_get($ctx, 'meta.pair_norm') ?? data_get($ctx, 'meta.pair') ?? '';
        $pairNormStr = (string) $pairNorm;

        // Exposure gates: max concurrent positions and per-pair exposure
        $maxOpen = (int) $rules->getGate('max_concurrent_positions', 3);
        $pairCap = (int) $rules->getRisk('pair_exposure_pct', 15);

        try {
            $openCount = (int) ($ledger->openPositionsCount() ?? 0);
        } catch (\Throwable $e) {
            $openCount = 0;
        }

        if ($openCount >= $maxOpen) {
            $reasons[] = 'max_concurrent';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // pair exposure percent for this pair (0-100)
        try {
            $pairExposure = (float) ($ledger->pairExposurePct($pairNormStr) ?? 0.0);
        } catch (\Throwable $e) {
            $pairExposure = 0.0;
        }

        if ($pairExposure >= $pairCap) {
            $reasons[] = 'pair_exposure_cap';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // Volatility / stretch gates (ADX and EMA-Z) - configured defaults
        $adxMin = (int) $rules->getGate('adx_min', 20);
        $zAbsMax = (float) $rules->getGate('z_abs_max', 1.0);

        $adxVal = data_get($ctx, 'features.adx5m');
        $zVal = data_get($ctx, 'features.ema20_z');

        if ($adxVal !== null && (float) $adxVal < $adxMin) {
            $reasons[] = 'low_adx';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        if ($zVal !== null && abs((float) $zVal) > $zAbsMax) {
            $reasons[] = 'stretched_z';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        // News tiering (optional - only run when configured)
        $label = 'weak';
        $dead_raw = $rules->getGate('news_threshold.deadband', null);
        $doNews = $dead_raw !== null;

        $dead = (float) $dead_raw;
        $mod = (float) $rules->getGate('news_threshold.moderate', 0.30);
        $strong = (float) $rules->getGate('news_threshold.strong', 0.45);

        $proposedAction = 'hold';
        $newsStrength = 0.0;
        if (! $doNews) {
            // If news gating is not configured, behave as before and return OK
            $reasons[] = 'ok';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons];
        }

        if ($doNews) {
            $newsStrength = (float) data_get($ctx, 'news.strength', 0);
            $newsDir = data_get($ctx, 'news.direction', 'neutral');
            $trend30m = data_get($ctx, 'features.trend30m', 'sideways');

            $label = 'weak';
            if ($newsStrength >= $strong) {
                $label = 'strong';
            } elseif ($newsStrength >= $mod) {
                $label = 'moderate';
            }

            $aligned = ($newsDir === 'buy' && $trend30m === 'up') || ($newsDir === 'sell' && $trend30m === 'down');

            if ($label === 'weak' || $newsDir === 'neutral') {
                $reasons[] = 'news_weak';

                return ['action' => 'hold', 'confidence' => round($newsStrength, 3), 'reasons' => $reasons, 'blocked' => true];
            }
            if ($label === 'moderate' && $rules->getConfluence('require_trend_alignment_for_moderate', true) && ! $aligned) {
                $reasons[] = 'needs_trend_align';

                return ['action' => 'hold', 'confidence' => round($newsStrength, 3), 'reasons' => $reasons, 'blocked' => true];
            }
            if ($label === 'strong' && ! $rules->getConfluence('allow_strong_against_trend', true) && ! $aligned) {
                $reasons[] = 'needs_trend_align';

                return ['action' => 'hold', 'confidence' => round($newsStrength, 3), 'reasons' => $reasons, 'blocked' => true];
            }

            $proposedAction = $newsDir === 'buy' ? 'buy' : ($newsDir === 'sell' ? 'sell' : 'hold');
        }

        // Contrarian sentiment gate
        $mode = (string) $rules->getSentimentGate('mode', 'contrarian');
        $th = (float) $rules->getSentimentGate('contrarian_threshold_pct', 65.0);
        $nbLo = (float) $rules->getSentimentGate('neutral_band_low_pct', 45.0);
        $nbHi = (float) $rules->getSentimentGate('neutral_band_high_pct', 55.0);

        $longPct = data_get($ctx, 'market.sentiment.long_pct');
        $shortPct = data_get($ctx, 'market.sentiment.short_pct');
        if (in_array($proposedAction, ['buy', 'sell'], true) && $longPct !== null && $shortPct !== null) {
            $longPctF = (float) $longPct;
            $shortPctF = (float) $shortPct;
            if (! ($longPctF >= $nbLo && $longPctF <= $nbHi)) {
                if ($mode === 'contrarian') {
                    if ($proposedAction === 'buy' && $longPctF >= $th) {
                        $reasons[] = 'contrarian_crowd_long';

                        return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
                    }
                    if ($proposedAction === 'sell' && $shortPctF >= $th) {
                        $reasons[] = 'contrarian_crowd_short';

                        return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
                    }
                }
            }
        }

        // Minimal sizing + SL/TP
        $riskMap = $rules->getRisk('per_trade_pct', null) ?? [];
        $perTradeCap = (float) $rules->getRisk('per_trade_cap_pct', 2.0);
        $slMult = (float) $rules->getExecution('sl_atr_mult', 2.0);
        $tpMult = (float) $rules->getExecution('tp_atr_mult', 4.0);
        $spreadCap = (float) $rules->getExecution('spread_ceiling_pips', 2.0);

        $last = (float) data_get($ctx, 'market.last_price', 0.0);
        $atrP = (float) data_get($ctx, 'market.atr5m_pips', 0.0);
        $spread = data_get($ctx, 'market.spread_estimate_pips');

        if ($spread !== null && $spread > $spreadCap) {
            $reasons[] = 'spread_too_wide';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons];
        }
        if ($atrP <= 0.0) {
            $reasons[] = 'atr_invalid';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons];
        }

        $riskPctRaw = $riskMap[$label] ?? $riskMap['default'] ?? 1.0;
        $riskPct = min((float) $riskPctRaw, $perTradeCap);

        $slPips = max(1e-6, $slMult * $atrP);

        // CRITICAL: Enforce minimum stop loss for margin safety (prevents margin rejection)
        $slMinPips = (float) $rules->getExecution('sl_min_pips', 15.0);
        $slPips = max($slPips, $slMinPips);

        $tpPips = max(1e-6, $tpMult * $atrP);

        $pairNorm = data_get($ctx, 'meta.pair_norm') ?? data_get($ctx, 'meta.pair') ?? '';
        $pairNormStr = (string) $pairNorm;
        $normForPip = strtoupper($pairNormStr);
        // If pair is provided without a delimiter (e.g. 'USDJPY'), insert a slash between base/quote
        if (strpos($normForPip, '/') === false && strpos($normForPip, '-') === false && strlen($normForPip) >= 6) {
            $normForPip = substr($normForPip, 0, 3).'/'.substr($normForPip, 3);
        }

        $pipSize = \App\Domain\FX\PipMath::pipSize($normForPip ?: '');

        $slDelta = $slPips * $pipSize;
        $tpDelta = $tpPips * $pipSize;

        if ($proposedAction === 'buy') {
            $entry = $last;
            $sl = $last - $slDelta;
            $tp = $last + $tpDelta;
        } elseif ($proposedAction === 'sell') {
            $entry = $last;
            $sl = $last + $slDelta;
            $tp = $last - $tpDelta;
        } else {
            $entry = $last;
            $sl = 0.0;
            $tp = 0.0;
        }

        // CRITICAL: Risk-Reward validation - enforce minimum RR ratio
        if ($proposedAction !== 'hold') {
            $actualRiskPips = abs($entry - $sl) / $pipSize;
            $actualRewardPips = abs($tp - $entry) / $pipSize;

            if ($actualRiskPips > 0) {
                $actualRR = $actualRewardPips / $actualRiskPips;
                $minRR = (float) $rules->getExecution('rr', 1.8);

                if ($actualRR < $minRR) {
                    $reasons[] = 'poor_risk_reward';

                    return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
                }
            }
        }

        // CRITICAL: Resistance/Support proximity filter
        if ($proposedAction === 'buy') {
            $resistanceLevels = data_get($ctx, 'features.resistanceLevels', []);
            $minDistancePips = 5.0; // Minimum 5 pips to nearest resistance

            foreach ($resistanceLevels as $level) {
                $distancePips = ($level - $entry) / $pipSize;
                if ($distancePips > 0 && $distancePips < $minDistancePips) {
                    $reasons[] = 'too_close_to_resistance';

                    return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
                }
            }
        } elseif ($proposedAction === 'sell') {
            $supportLevels = data_get($ctx, 'features.supportLevels', []);
            $minDistancePips = 5.0; // Minimum 5 pips to nearest support

            foreach ($supportLevels as $level) {
                $distancePips = ($entry - $level) / $pipSize;
                if ($distancePips > 0 && $distancePips < $minDistancePips) {
                    $reasons[] = 'too_close_to_support';

                    return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
                }
            }
        }

        // Compute stake/size using Risk Sizing helper
        $sleeveBalance = (float) data_get($ctx, 'meta.sleeve_balance', 10000.0);
        // Allow override via IG rules in market context
        $pipValue = (float) data_get($ctx, 'market.ig_rules.pip_value', 1.0);
        $sizeStep = (float) data_get($ctx, 'market.ig_rules.size_step', 0.01);

        $size = \App\Domain\Risk\Sizing::computeStake($sleeveBalance, $riskPct, $slPips, $pipValue, $sizeStep);

        // Round levels to tick size and enforce broker rules if present
        $normPair = strtoupper((string) ($pairNormStr));
        if (strpos($normPair, '/') === false && strpos($normPair, '-') === false && strlen($normPair) >= 6) {
            $normPair = substr($normPair, 0, 3).'/'.substr($normPair, 3);
        }

        $tick = \App\Domain\FX\PipMath::tickSize($normPair ?: '');

        // Helper to round to nearest tick
        $roundToTick = function (float $v) use ($tick): float {
            if ($tick <= 0.0) {
                return $v;
            }

            return round($v / $tick) * $tick;
        };

        $entry = $roundToTick($entry);
        $sl = $roundToTick($sl);
        $tp = $roundToTick($tp);

        // Apply IG rules if present
        $igRules = data_get($ctx, 'market.ig_rules');
        if (is_array($igRules) && ! empty($igRules)) {
            [$entry, $sl, $tp] = \App\Domain\Execution\LevelNormalizer::applyIgRules($normPair, $entry, $sl, $tp, $igRules);

            // Re-round after adjustment
            $entry = $roundToTick($entry);
            $sl = $roundToTick($sl);
            $tp = $roundToTick($tp);
        }

        $reasons[] = 'ok';

        $blocked = ($proposedAction === 'hold');

        $out = [
            'action' => $proposedAction,
            'confidence' => round($newsStrength, 3),
            'size' => $size,
            'blocked' => $blocked,
            'reasons' => $reasons,
            'news_label' => $label,
        ];

        if (! $blocked) {
            $out['risk_pct'] = round($riskPct, 3);
            $out['entry'] = $entry;
            $out['sl'] = round($sl, 6);
            $out['tp'] = round($tp, 6);
        }

        return $out;
    }

    /**
     * Check if current time is within optimal trading session based on rules configuration.
     *
     * @param  \DateTimeImmutable  $now  Current time in UTC
     * @param  AlphaRules  $rules  Rules configuration with session_filters
     * @return bool True if optimal, false if suboptimal
     */
    private function isOptimalTradingSession(\DateTimeImmutable $now, AlphaRules $rules): bool
    {
        $sessionFilter = $rules->getSessionFilter('default');
        if (! $sessionFilter) {
            // No session filtering configured - allow all times
            return true;
        }

        $gmtHour = (int) $now->format('H');
        $gmtMinute = (int) $now->format('i');
        $currentTimeMinutes = $gmtHour * 60 + $gmtMinute;

        // Check avoid_sessions
        if (isset($sessionFilter['avoid_sessions'])) {
            foreach ($sessionFilter['avoid_sessions'] as $session => $timeRange) {
                if ($this->isTimeInRange($currentTimeMinutes, $timeRange)) {
                    return false;
                }
            }
        }

        // Check preferred_sessions (if configured)
        if (isset($sessionFilter['preferred_sessions'])) {
            foreach ($sessionFilter['preferred_sessions'] as $session => $timeRange) {
                if ($this->isTimeInRange($currentTimeMinutes, $timeRange)) {
                    return true;
                }
            }

            // If preferred sessions are configured but current time doesn't match any, reject
            return false;
        }

        // No specific restrictions or preferences - allow
        return true;
    }

    /**
     * Check if current time (in minutes from midnight GMT) falls within a given time range.
     *
     * @param  int  $currentMinutes  Minutes from midnight GMT (0-1439)
     * @param  array  $range  Time range with 'start' and 'end' keys (in "HH:MM" format)
     * @return bool True if time is within range
     */
    private function isTimeInRange(int $currentMinutes, array $range): bool
    {
        if (! isset($range['start']) || ! isset($range['end'])) {
            return false;
        }

        $startMinutes = $this->parseTimeToMinutes($range['start']);
        $endMinutes = $this->parseTimeToMinutes($range['end']);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        // Handle ranges that cross midnight (e.g., 22:00-06:00)
        if ($startMinutes > $endMinutes) {
            return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
        }

        return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
    }

    /**
     * Parse time string "HH:MM" to minutes from midnight.
     *
     * @param  string  $timeStr  Time in "HH:MM" format
     * @return int|null Minutes from midnight, or null if invalid format
     */
    private function parseTimeToMinutes(string $timeStr): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }
}
