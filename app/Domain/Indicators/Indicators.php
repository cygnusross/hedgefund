<?php

namespace App\Domain\Indicators;

use App\Domain\Market\Bar;
use Ds\Vector;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;
use MathPHP\Statistics\Regression\Linear;
use MathPHP\Exception\BadDataException;
use MathPHP\Exception\OutOfBoundsException;
use MathPHP\Exception\IncorrectTypeException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\MatrixException;

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

        if (! function_exists('trader_ema')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $closes = array_map(fn (Bar $bar) => $bar->close, $bars);
        $emaSeries = trader_ema($closes, $period);
        if (! is_array($emaSeries) || $emaSeries === []) {
            return null;
        }

        $emaValue = end($emaSeries);

        return $emaValue === false ? null : (float) $emaValue;
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

        if (! function_exists('trader_atr')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $highs = array_map(fn (Bar $bar) => $bar->high, $bars);
        $lows = array_map(fn (Bar $bar) => $bar->low, $bars);
        $closes = array_map(fn (Bar $bar) => $bar->close, $bars);

        $atrSeries = trader_atr($highs, $lows, $closes, $period);
        if (! is_array($atrSeries) || $atrSeries === []) {
            return null;
        }

        $atr = end($atrSeries);

        return $atr === false ? null : (float) $atr;
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

        try {
            $mean = Average::mean($closes);
            $stdev = Descriptive::standardDeviation($closes);
        } catch (BadDataException | OutOfBoundsException $e) {
            return null;
        }

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
        $window = array_slice($closes, -$ma);
        $points = [];
        foreach ($window as $index => $price) {
            $points[] = [$index, $price];
        }

        try {
            $regression = new Linear($points);
            $parameters = $regression->getParameters();
            $slope = $parameters['m'];
        } catch (BadDataException | IncorrectTypeException | MatrixException | MathException $e) {
            return 'sideways';
        }

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
        if ($count < $period + 1) {
            return null;
        }

        if (! function_exists('trader_adx')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $highs = array_map(fn (Bar $bar) => $bar->high, $bars);
        $lows = array_map(fn (Bar $bar) => $bar->low, $bars);
        $closes = array_map(fn (Bar $bar) => $bar->close, $bars);

        $adxSeries = trader_adx($highs, $lows, $closes, $period);
        if (! is_array($adxSeries) || $adxSeries === []) {
            return null;
        }

        $adx = end($adxSeries);

        return $adx === false ? null : (float) $adx;
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
        $supports = new Vector();
        $resistances = new Vector();

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
                $resistances->push($bars[$i]->high);
            }
            if ($isLow) {
                $supports->push($bars[$i]->low);
            }
        }

        // most recent first already due to scanning direction
        return [
            'support' => array_slice($supports->toArray(), 0, 3),
            'resistance' => array_slice($resistances->toArray(), 0, 3),
        ];
    }

    /**
     * Relative Strength Index (RSI) using Wilder's smoothing method.
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $period  Period for RSI calculation (typically 14)
     * @return float|null RSI value between 0-100, or null if insufficient data
     */
    public static function rsi(array $bars, int $period = 14): ?float
    {
        $count = count($bars);
        if ($count < $period + 1) {
            return null;
        }

        if (! function_exists('trader_rsi')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $closes = array_map(fn (Bar $bar) => $bar->close, $bars);
        $rsiSeries = trader_rsi($closes, $period);
        if (! is_array($rsiSeries) || $rsiSeries === []) {
            return null;
        }

        $rsi = end($rsiSeries);

        return $rsi === false ? null : (float) $rsi;
    }

    /**
     * MACD (Moving Average Convergence Divergence) indicator.
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $fastPeriod  Fast EMA period (typically 12)
     * @param  int  $slowPeriod  Slow EMA period (typically 26)
     * @param  int  $signalPeriod  Signal line EMA period (typically 9)
     * @return array|null ['macd' => float, 'signal' => float, 'histogram' => float] or null
     */
    public static function macd(array $bars, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): ?array
    {
        $count = count($bars);
        $minBars = $slowPeriod + $signalPeriod;

        if ($count < $minBars) {
            return null;
        }

        if (! function_exists('trader_macd')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $closes = array_map(static fn (Bar $bar) => $bar->close, $bars);
        $macdResult = trader_macd($closes, $fastPeriod, $slowPeriod, $signalPeriod);
        if (! is_array($macdResult) || count($macdResult) < 3) {
            return null;
        }

        [$macdSeries, $signalSeries, $histSeries] = array_values($macdResult);
        if (! is_array($macdSeries) || $macdSeries === [] || ! is_array($signalSeries) || $signalSeries === [] || ! is_array($histSeries) || $histSeries === []) {
            return null;
        }

        $macdLine = end($macdSeries);
        $signalLine = end($signalSeries);
        $histogram = end($histSeries);

        if ($macdLine === false || $signalLine === false || $histogram === false) {
            return null;
        }

        return [
            'macd' => (float) $macdLine,
            'signal' => (float) $signalLine,
            'histogram' => (float) $histogram,
        ];
    }


    /**
     * Bollinger Bands indicator.
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $period  Period for moving average (typically 20)
     * @param  float  $multiplier  Standard deviation multiplier (typically 2.0)
     * @return array|null ['upper' => float, 'middle' => float, 'lower' => float] or null
     */
    public static function bollingerBands(array $bars, int $period = 20, float $multiplier = 2.0): ?array
    {
        $count = count($bars);
        if ($count < $period) {
            return null;
        }

        if (! function_exists('trader_bbands')) {
            throw new \RuntimeException('Trader extension is required but not available.');
        }

        $maType = defined('TRADER_MA_TYPE_SMA') ? constant('TRADER_MA_TYPE_SMA') : 0;
        $closes = array_map(fn (Bar $bar) => $bar->close, $bars);
        $bands = trader_bbands($closes, $period, $multiplier, $multiplier, $maType);
        if (! is_array($bands) || count($bands) !== 3) {
            return null;
        }

        [$upperSeries, $middleSeries, $lowerSeries] = array_values($bands);
        if (! is_array($upperSeries) || $upperSeries === [] || ! is_array($middleSeries) || $middleSeries === [] || ! is_array($lowerSeries) || $lowerSeries === []) {
            return null;
        }

        $upper = end($upperSeries);
        $middle = end($middleSeries);
        $lower = end($lowerSeries);

        if ($upper === false || $middle === false || $lower === false) {
            return null;
        }

        return [
            'upper' => (float) $upper,
            'middle' => (float) $middle,
            'lower' => (float) $lower,
        ];
    }


    /**
     * Stochastic Oscillator %K and %D.
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $kPeriod  Period for %K calculation (typically 14)
     * @param  int  $dPeriod  Period for %D smoothing (typically 3)
     * @return array|null ['k' => float, 'd' => float] or null
     */
    public static function stochastic(array $bars, int $kPeriod = 14, int $dPeriod = 3): ?array
    {
        $count = count($bars);
        if ($count < $kPeriod + $dPeriod - 1) {
            return null;
        }

        $kValues = [];

        // Calculate %K values
        for ($i = $kPeriod - 1; $i < $count; $i++) {
            $slice = array_slice($bars, $i - $kPeriod + 1, $kPeriod);

            $high = max(array_map(fn ($bar) => $bar->high, $slice));
            $low = min(array_map(fn ($bar) => $bar->low, $slice));
            $close = $bars[$i]->close;

            if ($high == $low) {
                $k = 50.0; // Avoid division by zero
            } else {
                $k = (($close - $low) / ($high - $low)) * 100.0;
            }

            $kValues[] = $k;
        }

        if (count($kValues) < $dPeriod) {
            return null;
        }

        // Current %K
        $currentK = end($kValues);

        // %D is SMA of last $dPeriod %K values
        $recentKValues = array_slice($kValues, -$dPeriod);
        try {
            $currentD = Average::mean($recentKValues);
        } catch (BadDataException $e) {
            return null;
        }

        return [
            'k' => $currentK,
            'd' => $currentD,
        ];
    }

    /**
     * Williams %R - Momentum oscillator that measures overbought/oversold levels.
     *
     * %R = (Highest High - Close) / (Highest High - Lowest Low) × -100
     *
     * Values range from -100 (oversold) to 0 (overbought)
     * Typically: %R > -20 = overbought, %R < -80 = oversold
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $period  Lookback period (typically 14)
     * @return float|null Williams %R value or null if insufficient data
     */
    public static function williamsR(array $bars, int $period = 14): ?float
    {
        $count = count($bars);
        if ($count < $period) {
            return null;
        }

        // Get the last $period bars
        $recentBars = array_slice($bars, -$period);

        // Find highest high and lowest low over the period
        $highs = array_map(fn ($bar) => $bar->high, $recentBars);
        $lows = array_map(fn ($bar) => $bar->low, $recentBars);

        $highestHigh = max($highs);
        $lowestLow = min($lows);

        // Current close is the last bar's close
        $currentClose = end($bars)->close;

        // Avoid division by zero
        $range = $highestHigh - $lowestLow;
        if ($range == 0) {
            return -50.0; // Neutral value when no range
        }

        // Calculate Williams %R
        $williamsR = (($highestHigh - $currentClose) / $range) * -100;

        return $williamsR;
    }

    /**
     * Commodity Channel Index (CCI) - Cyclical momentum oscillator.
     *
     * CCI = (Typical Price - SMA of TP) / (0.015 × Mean Deviation)
     *
     * Typical Price = (High + Low + Close) / 3
     * Mean Deviation = Average of |TP - SMA(TP)| over period
     *
     * Values interpretation:
     * > +100: Overbought (strong upward momentum)
     * < -100: Oversold (strong downward momentum)
     * -100 to +100: Normal trading range
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $period  Lookback period (typically 20)
     * @return float|null CCI value or null if insufficient data
     */
    public static function cci(array $bars, int $period = 20): ?float
    {
        $count = count($bars);
        if ($count < $period) {
            return null;
        }

        // Calculate Typical Prices for all bars
        $typicalPrices = [];
        foreach ($bars as $bar) {
            $typicalPrices[] = ($bar->high + $bar->low + $bar->close) / 3;
        }

        // Get the last $period typical prices
        $recentTPs = array_slice($typicalPrices, -$period);

        try {
            // Calculate Simple Moving Average of Typical Prices
            $smaTP = Average::mean($recentTPs);
            $meanDeviation = Descriptive::meanAbsoluteDeviation($recentTPs);
        } catch (BadDataException $e) {
            return null;
        }

        // Current Typical Price
        $currentTP = end($typicalPrices);

        // Avoid division by zero
        if ($meanDeviation == 0) {
            return 0.0; // Neutral value when no deviation
        }

        // Calculate CCI
        $cci = ($currentTP - $smaTP) / (0.015 * $meanDeviation);

        return $cci;
    }

    /**
     * Parabolic SAR - Dynamic trailing stop and trend reversal indicator.
     *
     * SAR = SAR_prev + AF × (EP - SAR_prev)
     *
     * Where:
     * - AF (Acceleration Factor): starts at 0.02, increases by 0.02 up to max 0.20
     * - EP (Extreme Point): highest high in uptrend, lowest low in downtrend
     * - SAR: Stop and Reverse level
     *
     * Trading Rules:
     * - Price above SAR = Uptrend (long signal)
     * - Price below SAR = Downtrend (short signal)
     * - SAR crossover = Trend reversal
     *
     * @param  Bar[]  $bars  oldest -> newest (minimum 5 bars)
     * @param  float  $afStep  Acceleration factor step (typically 0.02)
     * @param  float  $afMax  Maximum acceleration factor (typically 0.20)
     * @return array|null ['sar' => float, 'trend' => 'up'|'down'] or null
     */
    public static function parabolicSAR(array $bars, float $afStep = 0.02, float $afMax = 0.20): ?array
    {
        $count = count($bars);
        if ($count < 5) {
            return null; // Need minimum bars for initialization
        }

        // Initialize with first few bars
        $sar = [];
        $ep = []; // Extreme points
        $af = []; // Acceleration factors
        $trend = []; // up or down

        // First bar initialization - assume uptrend
        $sar[0] = $bars[0]->low;
        $ep[0] = $bars[0]->high;
        $af[0] = $afStep;
        $trend[0] = 'up';

        // Second bar
        if ($count > 1) {
            $sar[1] = $sar[0];
            $ep[1] = max($ep[0], $bars[1]->high);
            $af[1] = ($ep[1] > $ep[0]) ? min($af[0] + $afStep, $afMax) : $af[0];
            $trend[1] = 'up';
        }

        // Calculate SAR for remaining bars
        for ($i = 2; $i < $count; $i++) {
            $currentBar = $bars[$i];
            $prevSar = $sar[$i - 1];
            $prevEp = $ep[$i - 1];
            $prevAf = $af[$i - 1];
            $prevTrend = $trend[$i - 1];

            // Calculate next SAR
            $nextSar = $prevSar + $prevAf * ($prevEp - $prevSar);

            // Check for trend reversal
            $isReversal = false;
            if ($prevTrend === 'up') {
                // In uptrend, check if price breaks below SAR
                if ($currentBar->low <= $nextSar) {
                    $isReversal = true;
                    $nextSar = $prevEp; // SAR becomes the previous EP
                    $trend[$i] = 'down';
                    $ep[$i] = $currentBar->low; // New EP is current low
                    $af[$i] = $afStep; // Reset AF
                } else {
                    $trend[$i] = 'up';
                    $ep[$i] = max($prevEp, $currentBar->high); // Update EP if new high
                    $af[$i] = ($ep[$i] > $prevEp) ? min($prevAf + $afStep, $afMax) : $prevAf;

                    // SAR cannot be above the low of the current or previous bar
                    $nextSar = min($nextSar, min($currentBar->low, $bars[$i - 1]->low));
                }
            } else {
                // In downtrend, check if price breaks above SAR
                if ($currentBar->high >= $nextSar) {
                    $isReversal = true;
                    $nextSar = $prevEp; // SAR becomes the previous EP
                    $trend[$i] = 'up';
                    $ep[$i] = $currentBar->high; // New EP is current high
                    $af[$i] = $afStep; // Reset AF
                } else {
                    $trend[$i] = 'down';
                    $ep[$i] = min($prevEp, $currentBar->low); // Update EP if new low
                    $af[$i] = ($ep[$i] < $prevEp) ? min($prevAf + $afStep, $afMax) : $prevAf;

                    // SAR cannot be below the high of the current or previous bar
                    $nextSar = max($nextSar, max($currentBar->high, $bars[$i - 1]->high));
                }
            }

            $sar[$i] = $nextSar;
        }

        // Return the most recent SAR value and trend
        $lastIndex = $count - 1;

        return [
            'sar' => $sar[$lastIndex],
            'trend' => $trend[$lastIndex],
        ];
    }

    /**
     * True Range Bands - ATR-based dynamic support and resistance channels.
     *
     * Upper Band = EMA + (ATR × Multiplier)
     * Middle Band = EMA
     * Lower Band = EMA - (ATR × Multiplier)
     *
     * These bands adapt to volatility, expanding during high volatility periods
     * and contracting during low volatility. Unlike Bollinger Bands which use
     * standard deviation, TR Bands use Average True Range for volatility measure.
     *
     * Trading Applications:
     * - Price above upper band = Strong bullish momentum
     * - Price below lower band = Strong bearish momentum
     * - Price bouncing between bands = Range-bound market
     * - Band squeeze = Low volatility, potential breakout
     *
     * @param  Bar[]  $bars  oldest -> newest
     * @param  int  $emaPeriod  EMA period for center line (typically 20)
     * @param  int  $atrPeriod  ATR period (typically 14)
     * @param  float  $multiplier  ATR multiplier (typically 2.0)
     * @return array|null ['upper' => float, 'middle' => float, 'lower' => float] or null
     */
    public static function trueRangeBands(array $bars, int $emaPeriod = 20, int $atrPeriod = 14, float $multiplier = 2.0): ?array
    {
        $count = count($bars);
        $minBars = max($emaPeriod, $atrPeriod + 1);

        if ($count < $minBars) {
            return null;
        }

        // Calculate EMA for the middle band
        $ema = self::ema($bars, $emaPeriod);
        if ($ema === null) {
            return null;
        }

        // Calculate ATR for band width
        $atr = self::atr($bars, $atrPeriod);
        if ($atr === null) {
            return null;
        }

        // Calculate band levels
        $bandWidth = $atr * $multiplier;
        $upperBand = $ema + $bandWidth;
        $lowerBand = $ema - $bandWidth;

        return [
            'upper' => $upperBand,
            'middle' => $ema,
            'lower' => $lowerBand,
        ];
    }
}
