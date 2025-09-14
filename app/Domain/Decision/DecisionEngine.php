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
            $reasons[] = 'stale_data';

            return ['action' => 'hold', 'confidence' => 0.0, 'reasons' => $reasons, 'blocked' => true];
        }

        if (($ctx['calendar']['within_blackout'] ?? $ctx['blackout'] ?? false) === true) {
            $reasons[] = 'blackout';

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
}
