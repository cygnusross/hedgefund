<?php

declare(strict_types=1);

namespace App\Domain\Features;

use App\Domain\FX\PipMath;
use App\Domain\Indicators\Indicators;
use App\Domain\Market\Bar;
use App\Domain\Market\FeatureSet;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

final class FeatureEngine
{
    /**
     * Build FeatureSet at given timestamp using 5m and 30m bars.
     * Returns null if not enough warm-up data.
     * Pure function: no I/O.
     *
     * @param  Bar[]  $bars5m  oldest->newest
     * @param  Bar[]  $bars30m  oldest->newest
     */
    public static function buildAt(array $bars5m, array $bars30m, \DateTimeImmutable $ts, string $pair = 'EUR/USD'): ?FeatureSet
    {
        // slice bars5m up to and including $ts
        $idx = null;
        for ($i = count($bars5m) - 1; $i >= 0; $i--) {
            if ($bars5m[$i]->ts <= $ts) {
                $idx = $i;
                break;
            }
        }

        if ($idx === null) {
            return null; // no bars up to ts
        }

        $slice5 = array_slice($bars5m, 0, $idx + 1);

        // warm-up requirements
        $neededEma = 20;
        $neededAtr = 14;

        if (count($slice5) < max($neededEma, $neededAtr + 1)) {
            return null;
        }

        $ema20 = Indicators::ema($slice5, $neededEma);
        $atr5m = Indicators::atr($slice5, $neededAtr);

        if ($ema20 === null || $atr5m === null) {
            return null;
        }

        // Stage 2 features
        $ema20_z = Indicators::emaZ($slice5, $neededEma, $neededEma) ?? 0.0;
        // get recent price range then convert to pips using PipMath
        $recentRangePrice = Indicators::recentRangePrice($slice5, 360);
        $rawPips = PipMath::toPips($recentRangePrice, $pair);
        // JPY pairs typically use 2 decimal places in price (pip = 0.01) but reporting
        // pips with 1 decimal is more useful; other pairs can be integers for RR checks
        $parts = preg_split('/[\/\-]/', $pair);
        $quote = isset($parts[1]) ? strtoupper($parts[1]) : '';
        if ($quote === 'JPY') {
            $recentRangePips = round($rawPips, 1);
        } else {
            $recentRangePips = (float) round($rawPips);
        }

        // Stage 2.5: compute ADX on 5m
        $adx5m = Indicators::adx($slice5) ?? 0.0;

        // Stage 3: compute 30m trend label by slicing bars30m up to ts
        $idx30 = null;
        for ($i = count($bars30m) - 1; $i >= 0; $i--) {
            if ($bars30m[$i]->ts <= $ts) {
                $idx30 = $i;
                break;
            }
        }

        $slice30 = $idx30 === null ? [] : array_slice($bars30m, 0, $idx30 + 1);
        $trend30m = Indicators::trendLabel($slice30);

        // Stage 5: swing S/R on 5m
        $sr = Indicators::sr($slice5);
        $rawSupports = $sr['support'] ?? [];
        $rawResists = $sr['resistance'] ?? [];

        // Determine last price from the slice (most recent close)
        $lastBar = end($slice5);
        $lastPrice = $lastBar ? $lastBar->close : null;

        // Split and order supports/resistances relative to last price
        $supports = [];
        $resists = [];
        if (is_numeric($lastPrice)) {
            foreach ($rawSupports as $s) {
                if ($s < $lastPrice) {
                    $supports[] = $s;
                }
            }
            foreach ($rawResists as $r) {
                if ($r > $lastPrice) {
                    $resists[] = $r;
                }
            }

            // Sort supports descending (closest-first), resistances ascending (closest-first)
            rsort($supports, SORT_NUMERIC);
            sort($resists, SORT_NUMERIC);

            // Limit to top 3 each
            $supportLevels = array_slice($supports, 0, 3);
            $resistanceLevels = array_slice($resists, 0, 3);
        } else {
            // Fallback to raw values if last price not available
            $supportLevels = array_slice($rawSupports, 0, 3);
            $resistanceLevels = array_slice($rawResists, 0, 3);
        }

        return new FeatureSet(
            $ts,
            $ema20,
            $atr5m,
            $ema20_z,
            $recentRangePips,
            $adx5m,
            $trend30m,
            $supportLevels,
            $resistanceLevels,
        );
    }
}
