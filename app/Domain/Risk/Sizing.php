<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;

final class Sizing
{
    /**
     * Compute stake (size) such that riskAmount ~= slPips * pipValue * size
     *
     * @param  float  $sleeveBalance  Account/sleeve balance (in quote or account currency)
     * @param  float  $riskPct  Percent of balance to risk (e.g. 1.5 for 1.5%)
     * @param  float  $slPips  Stop loss in pips
     * @param  float  $pipValue  Value per pip per contract (in account currency)
     * @param  float  $sizeStep  Size step for broker (default 0.01)
     * @return float rounded size
     */
    public static function computeStake(float $sleeveBalance, float $riskPct, float $slPips, float $pipValue, float $sizeStep = 0.01): float
    {
        if ($slPips <= 0.0 || $pipValue <= 0.0 || $sizeStep <= 0.0) {
            return 0.0;
        }

        $balanceDecimal = Decimal::of($sleeveBalance);
        $riskPctDecimal = Decimal::of($riskPct)->dividedBy(Decimal::of(100), 12, RoundingMode::HALF_UP);
        $riskAmount = $balanceDecimal->multipliedBy($riskPctDecimal);

        $slPipsDecimal = Decimal::of($slPips);
        $pipValueDecimal = Decimal::of($pipValue);
        $denominator = $slPipsDecimal->multipliedBy($pipValueDecimal);

        if ($denominator->isZero()) {
            return 0.0;
        }

        $rawSize = $riskAmount->dividedBy($denominator, 12, RoundingMode::HALF_UP);

        $sizeStepDecimal = Decimal::of($sizeStep);
        if ($sizeStepDecimal->isZero()) {
            return 0.0;
        }

        $steps = $rawSize->dividedBy($sizeStepDecimal, 12, RoundingMode::DOWN)->toScale(0, RoundingMode::DOWN);
        $rounded = $steps->multipliedBy($sizeStepDecimal);

        return max(0.0, Decimal::toFloat($rounded, 12));
    }
}
