<?php

declare(strict_types=1);

namespace App\Domain\Risk;

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

        $riskAmount = $sleeveBalance * ($riskPct / 100.0);

        // raw size (contracts/units) that would produce riskAmount for given sl and pip value
        $rawSize = $riskAmount / ($slPips * $pipValue);

        // Round down to nearest size step (avoid oversizing risk)
        $rounded = floor($rawSize / $sizeStep) * $sizeStep;

        // Don't return negative
        return max(0.0, round($rounded, 12));
    }
}
