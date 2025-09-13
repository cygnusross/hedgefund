<?php

namespace App\Domain\Indicators;

use App\Domain\Market\Bar;

final class Indicators
{
    /**
     * Exponential moving average (EMA) for closes.
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function ema(array $bars, int $period): ?float
    {
        $count = count($bars);
        if ($count < $period) {
            return null;
        }

        // seed with SMA of first $period closes
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $bars[$i]->close;
        }
        $sma = $sum / $period;

        $mult = 2.0 / ($period + 1);

        // first EMA value corresponds to index $period - 1 (SMA), next applies to $period
        $ema = $sma;
        for ($i = $period; $i < $count; $i++) {
            $close = $bars[$i]->close;
            $ema = ($close - $ema) * $mult + $ema;
        }

        return $ema;
    }

    /**
     * Average True Range (Wilder smoothing)
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function atr(array $bars, int $period): ?float
    {
        $count = count($bars);
        if ($count < $period + 1) {
            return null;
        }

        $trs = [];
        for ($i = 1; $i < $count; $i++) {
            $high = $bars[$i]->high;
            $low = $bars[$i]->low;
            $prevClose = $bars[$i - 1]->close;

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trs[] = $tr;
        }

        if (count($trs) < $period) {
            return null;
        }

        // seed ATR as simple average of first $period TRs
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $trs[$i];
        }
        $atr = $sum / $period;

        // Wilder smoothing for remaining TRs
        for ($i = $period; $i < count($trs); $i++) {
            $tr = $trs[$i];
            $atr = (($atr * ($period - 1)) + $tr) / $period;
        }

        return $atr;
    }

    /**
     * EMA Z-score: (lastClose - EMA(emaPeriod)) / stdev(last stdevPeriod closes)
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function emaZ(array $bars, int $emaPeriod = 20, int $stdevPeriod = 20): ?float
    {
        $count = count($bars);
        if ($count < max($emaPeriod, $stdevPeriod)) {
            return null;
        }

        $ema = self::ema($bars, $emaPeriod);
        if ($ema === null) {
            return null;
        }

        // take last $stdevPeriod closes
        $start = max(0, $count - $stdevPeriod);
        $closes = [];
        for ($i = $start; $i < $count; $i++) {
            $closes[] = $bars[$i]->close;
        }

        $n = count($closes);
        if ($n === 0) {
            return null;
        }

        $mean = array_sum($closes) / $n;
        $sumSq = 0.0;
        foreach ($closes as $c) {
            $d = $c - $mean;
            $sumSq += $d * $d;
        }

        // sample standard deviation (n-1) if n>1, else 0
        $variance = ($n > 1) ? ($sumSq / ($n - 1)) : 0.0;
        $stdev = sqrt($variance);

        if ($stdev == 0.0) {
            return null;
        }

        $lastClose = end($closes);

        return ($lastClose - $ema) / $stdev;
    }

    /**
     * Compute recent range in pips over the last $minutes minutes.
     * Assumes bars are regular but uses timestamps to filter.
     * Returns 0.0 if no bars in window.
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function recentRangePips(array $bars, int $minutes = 360): float
    {
        $range = self::recentRangePrice($bars, $minutes);

        // backward compatible: multiply by 10000 for EUR/USD-style pips
        return $range * 10000.0;
    }

    /**
     * Return recent price range (high - low) over the provided bars within the timeframe.
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function recentRangePrice(array $bars, int $minutes = 360): float
    {
        $count = count($bars);
        if ($count === 0) {
            return 0.0;
        }

        $endTs = $bars[$count - 1]->ts;
        $cutoff = $endTs->modify("-{$minutes} minutes");

        $high = null;
        $low = null;
        for ($i = $count - 1; $i >= 0; $i--) {
            $b = $bars[$i];
            if ($b->ts < $cutoff) {
                break;
            }
            $high = $high === null ? $b->high : max($high, $b->high);
            $low = $low === null ? $b->low : min($low, $b->low);
        }

        if ($high === null || $low === null) {
            return 0.0;
        }

        return $high - $low;
    }

    /**
     * Simple 30m trend detector.
     *
     * @param  Bar[]  $bars30m  oldest -> newest
     */
    public static function trendLabel(array $bars30m, int $ma = 20, float $flat = 0.00005): string
    {
        $count = count($bars30m);
        if ($count < $ma + 5) {
            return 'sideways';
        }

        // compute simple moving averages for the last (ma + 5) closes
        $closes = array_map(fn (Bar $b) => $b->close, $bars30m);

        $lastIndex = $count - 1;
        $k = 5;

        // helper to compute SMA ending at index $end (inclusive)
        $smaAt = function (int $end) use ($closes, $ma) {
            $start = $end - $ma + 1;
            if ($start < 0) {
                return null;
            }
            $sum = 0.0;
            for ($i = $start; $i <= $end; $i++) {
                $sum += $closes[$i];
            }

            return $sum / $ma;
        };

        $maNow = $smaAt($lastIndex);
        $maPast = $smaAt($lastIndex - $k);

        if ($maNow === null || $maPast === null) {
            return 'sideways';
        }

        $slope = $maNow - $maPast;

        if ($slope > $flat) {
            return 'up';
        }
        if ($slope < -$flat) {
            return 'down';
        }

        return 'sideways';
    }

    /**
     * Average Directional Index (ADX) using Wilder's smoothing
     *
     * @param  Bar[]  $bars  oldest -> newest
     */
    public static function adx(array $bars, int $period = 14): ?float
    {
        $count = count($bars);
        // Need at least period+1 bars to compute TR/DM series and period more for ADX
        if ($count < $period + 1) {
            return null;
        }

        $trs = [];
        $plusDM = [];
        $minusDM = [];

        for ($i = 1; $i < $count; $i++) {
            $high = $bars[$i]->high;
            $low = $bars[$i]->low;
            $prevHigh = $bars[$i - 1]->high;
            $prevLow = $bars[$i - 1]->low;
            $prevClose = $bars[$i - 1]->close;

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trs[] = $tr;

            $upMove = $high - $prevHigh;
            $downMove = $prevLow - $low;

            $pdm = 0.0;
            $mdm = 0.0;
            if ($upMove > $downMove && $upMove > 0) {
                $pdm = $upMove;
            }
            if ($downMove > $upMove && $downMove > 0) {
                $mdm = $downMove;
            }

            $plusDM[] = $pdm;
            $minusDM[] = $mdm;
        }

        if (count($trs) < $period) {
            return null;
        }

        // Wilder smoothing: seed with sum of first period values
        $sumTR = 0.0;
        $sumPDM = 0.0;
        $sumMDM = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sumTR += $trs[$i];
            $sumPDM += $plusDM[$i];
            $sumMDM += $minusDM[$i];
        }

        $smoothedTR = $sumTR;
        $smoothedPDM = $sumPDM;
        $smoothedMDM = $sumMDM;

        $plusDI = [];
        $minusDI = [];

        // First DI values for index = period - 1 (corresponds to trs index period-1)
        $firstPlusDI = ($smoothedTR == 0.0) ? 0.0 : (100.0 * ($smoothedPDM / $smoothedTR));
        $firstMinusDI = ($smoothedTR == 0.0) ? 0.0 : (100.0 * ($smoothedMDM / $smoothedTR));
        $plusDI[] = $firstPlusDI;
        $minusDI[] = $firstMinusDI;

        // Continue smoothing for remaining TRs starting at index = period
        for ($i = $period; $i < count($trs); $i++) {
            $tr = $trs[$i];
            $pdm = $plusDM[$i];
            $mdm = $minusDM[$i];

            $smoothedTR = $smoothedTR - ($smoothedTR / $period) + $tr;
            $smoothedPDM = $smoothedPDM - ($smoothedPDM / $period) + $pdm;
            $smoothedMDM = $smoothedMDM - ($smoothedMDM / $period) + $mdm;

            $pdi = ($smoothedTR == 0.0) ? 0.0 : (100.0 * ($smoothedPDM / $smoothedTR));
            $mdi = ($smoothedTR == 0.0) ? 0.0 : (100.0 * ($smoothedMDM / $smoothedTR));

            $plusDI[] = $pdi;
            $minusDI[] = $mdi;
        }

        // compute DX series for each DI pair
        $dxs = [];
        $len = count($plusDI);
        for ($i = 0; $i < $len; $i++) {
            $p = $plusDI[$i];
            $m = $minusDI[$i];
            $den = $p + $m;
            $dx = ($den == 0.0) ? 0.0 : (100.0 * (abs($p - $m) / $den));
            $dxs[] = $dx;
        }

        if (count($dxs) < $period) {
            return null;
        }

        // ADX seed: average of first $period DXs
        $sumDx = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sumDx += $dxs[$i];
        }
        $adx = $sumDx / $period;

        // Wilder smoothing of DX to ADX for remaining
        for ($i = $period; $i < count($dxs); $i++) {
            $adx = (($adx * ($period - 1)) + $dxs[$i]) / $period;
        }

        return $adx;
    }

    /**
     * Simple pivot-based swing support/resistance detection.
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @return array{support: float[], resistance: float[]}
     */
    public static function sr(array $bars, int $lookback = 120, int $pivot = 3): array
    {
        $count = count($bars);
        if ($count === 0) {
            return ['support' => [], 'resistance' => []];
        }

        $start = max(0, $count - $lookback);
        $supports = [];
        $resistances = [];

        // scan from newest to oldest to get most recent pivots first
        for ($i = $count - 1; $i >= $start; $i--) {
            $isHigh = true;
            $isLow = true;

            for ($j = 1; $j <= $pivot; $j++) {
                $left = $i - $j;
                $right = $i + $j;

                if ($left < $start) {
                    $isHigh = $isHigh && true; // ignore
                    $isLow = $isLow && true;
                } else {
                    if ($bars[$i]->high <= $bars[$left]->high) {
                        $isHigh = false;
                    }
                    if ($bars[$i]->low >= $bars[$left]->low) {
                        $isLow = false;
                    }
                }

                if ($right >= $count) {
                    // ignore right side if out of range (recent bars)
                } else {
                    if ($bars[$i]->high <= $bars[$right]->high) {
                        $isHigh = false;
                    }
                    if ($bars[$i]->low >= $bars[$right]->low) {
                        $isLow = false;
                    }
                }
            }

            if ($isHigh) {
                $resistances[] = $bars[$i]->high;
            }
            if ($isLow) {
                $supports[] = $bars[$i]->low;
            }

            if (count($supports) >= 3 && count($resistances) >= 3) {
                break;
            }
        }

        // most recent first already due to scanning direction
        return ['support' => array_slice($supports, 0, 3), 'resistance' => array_slice($resistances, 0, 3)];
    }
}
