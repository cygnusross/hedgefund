<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Decision\Contracts\LiveDecisionEngineContract;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\DTO\DecisionResult;
use App\Domain\Execution\NullPositionLedger;
use App\Domain\Execution\PositionLedgerContract;
use App\Domain\FX\PipMath;
use App\Domain\Risk\Sizing;
use App\Domain\Rules\AlphaRules;
use App\Domain\Rules\RuleContextManager;
use App\Support\Clock\ClockInterface;
use App\Support\Clock\SystemClock;
use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

use function data_get;

final class LiveDecisionEngine implements LiveDecisionEngineContract
{
    private readonly ClockInterface $clock;

    private readonly PositionLedgerContract $ledger;

    public function __construct(
        private readonly AlphaRules $rules,
        ?ClockInterface $clock = null,
        ?PositionLedgerContract $ledger = null,
        private readonly ?RuleContextManager $ruleContextManager = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->ledger = $ledger ?? new NullPositionLedger;
    }

    public function decide(DecisionRequest $request): DecisionResult
    {
        $context = $request->context();
        $features = new FeatureExtractor($context);
        $rulesSnapshot = $features->getRules();
        $layeredRules = $rulesSnapshot?->layered();
        $baseRuleData = $this->rules->toArray();
        $sentinel = new \stdClass;
        $ruleValue = function (string $path, mixed $default = null) use ($layeredRules, $baseRuleData, $sentinel) {
            if ($layeredRules !== null) {
                $value = data_get($layeredRules, $path, $sentinel);
                if ($value !== $sentinel) {
                    return $value;
                }
            }

            return data_get($baseRuleData, $path, $default);
        };
        $reasons = [];

        $requiredStatuses = $this->normalizeGate($ruleValue('gates.market_required_status', ['TRADEABLE']));
        $marketStatus = $features->getMarketStatus();
        if ($marketStatus === null || ! in_array($marketStatus, $requiredStatuses, true)) {
            $reasons[] = 'status_closed';

            return $this->buildBlockedResponse($reasons);
        }

        $dataAge = $features->getDataAge();
        $maxDataAge = (int) $ruleValue('gates.max_data_age_sec', 600);
        if ($dataAge === null || $dataAge > $maxDataAge) {
            $reasons[] = $dataAge === null ? 'no_bar_data' : 'bar_data_stale';

            return $this->buildBlockedResponse($reasons);
        }

        if ($features->isWithinBlackout()) {
            $reasons[] = 'blackout';

            return $this->buildBlockedResponse($reasons);
        }

        $sessionMultiplier = $this->getSessionMultiplier($context->timestamp(), $layeredRules);

        $spreadRequired = (bool) $ruleValue('gates.spread_required', false);
        $spread = $features->getSpread();
        if ($spreadRequired && $spread === null) {
            $reasons[] = 'no_spread';

            return $this->buildBlockedResponse($reasons);
        }

        $adxOverride = $features->getGateOverride('adx_min');
        $adxMinConfigured = $adxOverride ?? $ruleValue('gates.adx_min', null);
        if ($adxMinConfigured !== null) {
            $adxMin = (int) $adxMinConfigured;
            $adxValue = $features->getAdx();
            if ($adxValue === null || $adxValue < $adxMin) {
                $reasons[] = 'low_adx';

                return $this->buildBlockedResponse($reasons);
            }
        }

        $zOverride = $features->getGateOverride('z_abs_max');
        $zAbsConfigured = $zOverride ?? $ruleValue('gates.z_abs_max', null);
        if ($zAbsConfigured !== null) {
            $zAbsMax = (float) $zAbsConfigured;
            $zValue = $features->getEmaZ();
            if ($zValue !== null && abs($zValue) > $zAbsMax) {
                $reasons[] = 'stretched_z';

                return $this->buildBlockedResponse($reasons);
            }
        }

        $rsi = $features->getRsi();
        $stochK = $features->getStochasticK();
        $williamsR = $features->getWilliamsR();
        $cci = $features->getCci();
        $parabolicSar = $features->getParabolicSAR();
        $parabolicSarTrend = $features->getParabolicSARTrend();
        $trueRangeUpper = $features->getTrueRangeUpper();
        $trueRangeLower = $features->getTrueRangeLower();
        $lastPrice = $features->getLastPrice();

        $rsiOverbought = (float) $ruleValue('gates.rsi_overbought', 75);
        $rsiOversold = (float) $ruleValue('gates.rsi_oversold', 25);

        if ($rsi !== null) {
            if ($rsi > $rsiOverbought) {
                $reasons[] = 'rsi_overbought';

                return $this->buildBlockedResponse($reasons);
            }
            if ($rsi < $rsiOversold) {
                $reasons[] = 'rsi_oversold';

                return $this->buildBlockedResponse($reasons);
            }
        }

        $stochExtreme = (float) $ruleValue('gates.stoch_extreme', 95);
        if ($stochK !== null && ($stochK > $stochExtreme || $stochK < (100 - $stochExtreme))) {
            $reasons[] = 'stoch_extreme';

            return $this->buildBlockedResponse($reasons);
        }

        $williamsOverbought = (float) $ruleValue('gates.williams_r_overbought', -20);
        $williamsOversold = (float) $ruleValue('gates.williams_r_oversold', -80);
        if ($williamsR !== null) {
            if ($williamsR > $williamsOverbought) {
                $reasons[] = 'williams_r_overbought';

                return $this->buildBlockedResponse($reasons);
            }
            if ($williamsR < $williamsOversold) {
                $reasons[] = 'williams_r_oversold';

                return $this->buildBlockedResponse($reasons);
            }
        }

        $cciOverbought = (float) $ruleValue('gates.cci_overbought', 100);
        $cciOversold = (float) $ruleValue('gates.cci_oversold', -100);
        if ($cci !== null) {
            if ($cci > $cciOverbought) {
                $reasons[] = 'cci_overbought';

                return $this->buildBlockedResponse($reasons);
            }
            if ($cci < $cciOversold) {
                $reasons[] = 'cci_oversold';

                return $this->buildBlockedResponse($reasons);
            }
        }

        // News-based SAR and True Range alignment checks disabled since news is removed
        // These checks were dependent on news direction which is no longer available

        $todaysPnL = $this->safeFloat(fn () => $this->ledger->todaysPnLPct());
        $dailyStop = (float) $ruleValue('gates.daily_loss_stop_pct', 3.0);
        if ($todaysPnL <= -1.0 * $dailyStop) {
            $reasons[] = 'daily_loss_stop';

            return $this->buildBlockedResponse($reasons);
        }

        $cooldownLossMinutes = (int) $this->rules->getCooldown('after_loss_minutes', 20);
        $cooldownWinMinutes = (int) $this->rules->getCooldown('after_win_minutes', 5);
        $lastTrade = $this->safeArray(fn () => $this->ledger->lastTrade());
        if (is_array($lastTrade) && isset($lastTrade['outcome'], $lastTrade['ts']) && $lastTrade['ts'] instanceof DateTimeImmutable) {
            $minutesAgo = $this->minutesAgo($lastTrade['ts']);
            if ($lastTrade['outcome'] === 'loss' && $minutesAgo <= $cooldownLossMinutes) {
                $reasons[] = 'cooldown_active';

                return $this->buildBlockedResponse($reasons);
            }

            if ($lastTrade['outcome'] === 'win' && $minutesAgo <= $cooldownWinMinutes) {
                $reasons[] = 'cooldown_active';

                return $this->buildBlockedResponse($reasons);
            }
        }

        $pairNorm = $features->getPairNorm();
        $maxOpenPositions = (int) $this->rules->getGate('max_concurrent_positions', 3);
        $openPositions = (int) $this->safeFloat(fn () => $this->ledger->openPositionsCount());
        if ($openPositions >= $maxOpenPositions) {
            $reasons[] = 'max_concurrent';

            return $this->buildBlockedResponse($reasons);
        }

        $pairExposureCap = (float) $ruleValue('risk.pair_exposure_pct', 15);
        $pairExposure = (float) $this->safeFloat(fn () => $this->ledger->pairExposurePct($pairNorm));
        if ($pairExposure >= $pairExposureCap) {
            $reasons[] = 'pair_exposure_cap';

            return $this->buildBlockedResponse($reasons);
        }

        // News functionality has been removed - sentiment evaluation works independently
        // Default to allowing sentiment evaluation with a neutral proposed action
        $proposedAction = 'buy'; // Use 'buy' as default to allow sentiment evaluation to work

        $sentiment = $features->getSentiment();
        if ($sentiment !== null && in_array($proposedAction, ['buy', 'sell'], true)) {
            $mode = (string) $ruleValue('gates.sentiment.mode', 'contrarian');
            $threshold = (float) $ruleValue('gates.sentiment.contrarian_threshold_pct', 65.0);
            $neutralLow = (float) $ruleValue('gates.sentiment.neutral_band_pct.0', 45.0);
            $neutralHigh = (float) $ruleValue('gates.sentiment.neutral_band_pct.1', 55.0);

            $longPct = $sentiment->longPct();
            $shortPct = $sentiment->shortPct();
            if ($longPct !== null && $shortPct !== null) {
                if ($mode === 'contrarian') {
                    if ($proposedAction === 'buy' && $longPct >= $threshold && ! ($longPct >= $neutralLow && $longPct <= $neutralHigh)) {
                        $reasons[] = 'contrarian_crowd_long';

                        return $this->buildBlockedResponse($reasons);
                    }

                    if ($proposedAction === 'sell' && $shortPct >= $threshold && ! ($longPct >= $neutralLow && $longPct <= $neutralHigh)) {
                        $reasons[] = 'contrarian_crowd_short';

                        return $this->buildBlockedResponse($reasons);
                    }
                }
            }
        }

        $riskMap = $ruleValue('risk.per_trade_pct', []);
        $perTradeCap = (float) $ruleValue('risk.per_trade_cap_pct', 2.0);
        $slMult = (float) $ruleValue('execution.sl_atr_mult', 2.0);
        $tpMult = (float) $ruleValue('execution.tp_atr_mult', 4.0);
        $spreadCap = (float) $ruleValue('execution.spread_ceiling_pips', 2.0);

        $marketAtr = $features->market()->atr5mPips() ?? 0.0;
        $lastMarketPrice = $features->getLastPrice() ?? 0.0;

        if ($spread !== null && $spread > $spreadCap) {
            $reasons[] = 'spread_too_wide';

            return $this->buildBlockedResponse($reasons);
        }

        if ($marketAtr <= 0.0) {
            $reasons[] = 'atr_invalid';

            return $this->buildBlockedResponse($reasons);
        }

        $atrOverride = $features->getGateOverride('atr_min_pips');
        $atrMinConfigured = $atrOverride ?? $ruleValue('gates.atr_min_pips', null);
        if ($atrMinConfigured !== null && $marketAtr < (float) $atrMinConfigured) {
            $reasons[] = 'atr_too_low';

            return $this->buildBlockedResponse($reasons);
        }

        $riskPctRaw = $riskMap['default'] ?? 1.0;
        $riskPct = min((float) $riskPctRaw, $perTradeCap);

        $slMultDecimal = Decimal::of($slMult);
        $tpMultDecimal = Decimal::of($tpMult);
        $marketAtrDecimal = Decimal::of($marketAtr);
        $epsilon = Decimal::of(0.000001);

        $slPipsDecimal = $slMultDecimal->multipliedBy($marketAtrDecimal);
        if ($slPipsDecimal->isLessThan($epsilon)) {
            $slPipsDecimal = $epsilon;
        }

        $slMinPipsDecimal = Decimal::of((float) $ruleValue('execution.sl_min_pips', 15.0));
        $originalSlPipsDecimal = $slPipsDecimal;
        if ($slPipsDecimal->isLessThan($slMinPipsDecimal)) {
            $slPipsDecimal = $slMinPipsDecimal;
        }

        $tpPipsDecimal = $tpMultDecimal->multipliedBy($marketAtrDecimal);
        if ($tpPipsDecimal->isLessThan($epsilon)) {
            $tpPipsDecimal = $epsilon;
        }

        if ($slPipsDecimal->isGreaterThan($originalSlPipsDecimal) && ! $originalSlPipsDecimal->isZero()) {
            $ratio = $slPipsDecimal->dividedBy($originalSlPipsDecimal, 12, RoundingMode::HALF_UP);
            $tpPipsDecimal = $tpPipsDecimal->multipliedBy($ratio);
        }

        $pipSymbol = $this->normalizePairForPips($pairNorm);
        $pipSize = PipMath::pipSize($pipSymbol);
        $pipSizeDecimal = Decimal::of($pipSize);

        $slDeltaDecimal = $slPipsDecimal->multipliedBy($pipSizeDecimal);
        $tpDeltaDecimal = $tpPipsDecimal->multipliedBy($pipSizeDecimal);

        $entryDecimal = Decimal::of($lastMarketPrice);
        $slDecimal = $entryDecimal;
        $tpDecimal = $entryDecimal;

        if ($proposedAction === 'buy') {
            $slDecimal = $entryDecimal->minus($slDeltaDecimal);
            $tpDecimal = $entryDecimal->plus($tpDeltaDecimal);
        } elseif ($proposedAction === 'sell') {
            $slDecimal = $entryDecimal->plus($slDeltaDecimal);
            $tpDecimal = $entryDecimal->minus($tpDeltaDecimal);
        }

        $entry = Decimal::toFloat($entryDecimal);
        $sl = Decimal::toFloat($slDecimal);
        $tp = Decimal::toFloat($tpDecimal);
        $slPips = Decimal::toFloat($slPipsDecimal);

        if ($proposedAction === 'buy') {
            foreach ($features->features()->resistanceLevels as $level) {
                $distancePips = ($level - $entry) / $pipSize;
                if ($distancePips > 0 && $distancePips < 5.0) {
                    $reasons[] = 'too_close_to_resistance';

                    return $this->buildBlockedResponse($reasons);
                }
            }
        } elseif ($proposedAction === 'sell') {
            foreach ($features->features()->supportLevels as $level) {
                $distancePips = ($entry - $level) / $pipSize;
                if ($distancePips > 0 && $distancePips < 5.0) {
                    $reasons[] = 'too_close_to_support';

                    return $this->buildBlockedResponse($reasons);
                }
            }
        }

        $sleeveBalance = $features->getSleeveBalance();
        $igRules = $features->getIgRules();
        $pipValue = $igRules?->pipValue() ?? $this->getDefaultPipValue($features->getPairNorm());
        $sizeStep = $igRules?->sizeStep() ?? 0.01;

        $size = Sizing::computeStake($sleeveBalance, $riskPct, $slPips, $pipValue, $sizeStep);

        $tick = PipMath::tickSize($pipSymbol);
        $entry = $this->roundToTick($entry, $tick);
        $sl = $this->roundToTick($sl, $tick);
        $tp = $this->roundToTick($tp, $tick);

        $levelsAdjusted = false;
        if ($igRules !== null) {
            [$entryNormalized, $slNormalized, $tpNormalized] = \App\Domain\Execution\LevelNormalizer::applyIgRules(
                $pipSymbol,
                $entry,
                $sl,
                $tp,
                $igRules->raw()
            );

            if ($entryNormalized !== $entry || $slNormalized !== $sl || $tpNormalized !== $tp) {
                $levelsAdjusted = true;
            }

            $entry = $this->roundToTick($entryNormalized, $tick);
            $sl = $this->roundToTick($slNormalized, $tick);
            $tp = $this->roundToTick($tpNormalized, $tick);
        }

        if ($proposedAction !== 'hold') {
            $actualRiskPips = abs($entry - $sl) / $pipSize;
            $actualRewardPips = abs($tp - $entry) / $pipSize;

            if ($actualRiskPips > 0.0) {
                $actualRr = $actualRewardPips / $actualRiskPips;
                $targetRr = (float) $ruleValue('execution.rr', 1.8);
                $minRr = (float) $ruleValue('execution.min_rr', $targetRr);

                if ($actualRr < $minRr) {
                    $reasons[] = 'poor_risk_reward';

                    return $this->buildBlockedResponse($reasons);
                }
            }
        }

        $reasons[] = 'ok';
        if ($levelsAdjusted) {
            $reasons[] = 'levels_adjusted_for_ig_rules';
        }

        if ($sessionMultiplier < 1.0) {
            $reasons[] = 'session_timing_penalty';
        } elseif ($sessionMultiplier > 1.0) {
            $reasons[] = 'session_timing_boost';
        }

        $blocked = ($proposedAction === 'hold');
        // Since news is removed, use a default base confidence when making a trading decision
        $baseConfidence = $proposedAction !== 'hold' ? 0.75 : 0.0;
        $confidence = $this->scaledProduct($baseConfidence, $sessionMultiplier, 3);

        if ($blocked) {
            return new DecisionResult('hold', $confidence, $reasons, true);
        }

        $sizePayload = $proposedAction === 'hold' ? null : $size;
        $riskPayload = $proposedAction === 'hold' ? null : $this->toScaledFloat($riskPct, 3);
        $entryPayload = $proposedAction === 'hold' ? null : $this->toScaledFloat($entry, 6);
        $slPayload = $proposedAction === 'hold' ? null : $this->toScaledFloat($sl, 6);
        $tpPayload = $proposedAction === 'hold' ? null : $this->toScaledFloat($tp, 6);

        return new DecisionResult(
            $proposedAction,
            $confidence,
            $reasons,
            false,
            $sizePayload,
            $riskPayload,
            $entryPayload,
            $slPayload,
            $tpPayload,
        );
    }

    /**
     * @return array<int, string>
     */
    private function normalizeGate(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return [is_string($value) ? $value : (string) $value];
    }

    private function getSessionMultiplier(DateTimeImmutable $now, ?array $layeredRules = null): float
    {
        $sessionFilter = $layeredRules !== null
            ? data_get($layeredRules, 'session_filters.default', [])
            : $this->rules->getSessionFilter('default');
        if (! is_array($sessionFilter) || $sessionFilter === []) {
            return 1.0;
        }

        $currentMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');

        if (isset($sessionFilter['preferred_sessions']) && is_array($sessionFilter['preferred_sessions'])) {
            foreach ($sessionFilter['preferred_sessions'] as $range) {
                if ($this->isTimeInRange($currentMinutes, $range)) {
                    return 1.2;
                }
            }
        }

        if (isset($sessionFilter['avoid_sessions']) && is_array($sessionFilter['avoid_sessions'])) {
            foreach ($sessionFilter['avoid_sessions'] as $range) {
                if ($this->isTimeInRange($currentMinutes, $range)) {
                    return 0.7;
                }
            }
        }

        return 1.0;
    }

    /**
     * @param  array{start?: string, end?: string}  $range
     */
    private function isTimeInRange(int $currentMinutes, array $range): bool
    {
        if (! isset($range['start'], $range['end'])) {
            return false;
        }

        $start = $this->parseTimeToMinutes($range['start']);
        $end = $this->parseTimeToMinutes($range['end']);

        if ($start === null || $end === null) {
            return false;
        }

        if ($start > $end) {
            return $currentMinutes >= $start || $currentMinutes <= $end;
        }

        return $currentMinutes >= $start && $currentMinutes <= $end;
    }

    private function parseTimeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /**
     * @param  callable():float|int|null  $callback
     */
    private function safeFloat(callable $callback): float
    {
        try {
            $value = $callback();

            return is_numeric($value) ? (float) $value : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * @param  callable():array|null  $callback
     */
    private function safeArray(callable $callback): ?array
    {
        try {
            $value = $callback();

            return is_array($value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function minutesAgo(DateTimeImmutable $when): int
    {
        $now = $this->clock->now();

        return max(0, (int) ceil(($now->getTimestamp() - $when->getTimestamp()) / 60));
    }

    private function roundToTick(float $value, float $tick): float
    {
        if ($tick <= 0.0) {
            return $value;
        }

        $valueDecimal = Decimal::of($value);
        $tickDecimal = Decimal::of($tick);

        $steps = $valueDecimal
            ->dividedBy($tickDecimal, 12, RoundingMode::HALF_UP)
            ->toScale(0, RoundingMode::HALF_UP);

        $rounded = $steps->multipliedBy($tickDecimal);

        return Decimal::toFloat($rounded, 12);
    }

    private function toScaledFloat(float $value, int $scale): float
    {
        $decimal = Decimal::of($value)->toScale($scale, RoundingMode::HALF_UP);

        return Decimal::toFloat($decimal, $scale);
    }

    private function scaledProduct(float $value, float $multiplier, int $scale = 3): float
    {
        $product = Decimal::of($value)->multipliedBy(Decimal::of($multiplier))->toScale($scale, RoundingMode::HALF_UP);

        return Decimal::toFloat($product, $scale);
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function buildBlockedResponse(array $reasons, float $confidence = 0.0): DecisionResult
    {
        return new DecisionResult('hold', $confidence, $reasons, true);
    }

    private function normalizePairForPips(string $pair): string
    {
        $norm = strtoupper($pair);
        if (str_contains($norm, '/') || str_contains($norm, '-')) {
            return str_replace('-', '/', $norm);
        }

        return strlen($norm) >= 6
            ? substr($norm, 0, 3).'/'.substr($norm, 3)
            : $norm;
    }

    /**
     * Get default pip value for common currency pairs when IG rules are not available
     */
    private function getDefaultPipValue(string $pairNorm): float
    {
        return match ($pairNorm) {
            'EURUSD', 'GBPUSD', 'AUDUSD', 'NZDUSD' => 10.0, // Major USD pairs: 1 pip = $10 per standard lot
            'USDCHF', 'USDCAD' => 10.0, // USD base pairs: 1 pip = $10 per standard lot
            'USDJPY' => 10.0, // Special case: 1 pip = $10 per standard lot
            'EURGBP', 'EURJPY', 'GBPJPY', 'AUDJPY', 'NZDJPY' => 10.0, // Cross pairs: approximate $10 per standard lot
            default => 10.0, // Default fallback: assume $10 per pip per standard lot
        };
    }
}
