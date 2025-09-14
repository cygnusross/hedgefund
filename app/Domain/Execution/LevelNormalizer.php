<?php

declare(strict_types=1);

namespace App\Domain\Execution;

final class LevelNormalizer
{
    /**
     * Ensure SL/TP are at least minDistancePoints away from entry. minDistancePoints is in points (i.e., ticks).
     * Adjusts SL/TP outward (farther from entry) to meet minimum when necessary.
     * Returns array [entry, sl, tp]
     */
    public static function applyIgRules(string $pair, float $entry, float $sl, float $tp, array $igRules): array
    {
        $minPoints = $igRules['minNormalStopOrLimitDistance'] ?? null;
        if ($minPoints === null) {
            return [$entry, $sl, $tp];
        }

        $minPoints = (float) $minPoints;
        // Convert minPoints (points) to price delta using pip/tick size
        $tick = \App\Domain\FX\PipMath::tickSize($pair);
        $minDelta = $minPoints * $tick;

        // If buy: sl < entry < tp. Ensure entry - sl >= minDelta and tp - entry >= minDelta
        if ($sl < $entry && $tp > $entry) {
            if (($entry - $sl) < $minDelta) {
                $sl = $entry - $minDelta;
            }
            if (($tp - $entry) < $minDelta) {
                $tp = $entry + $minDelta;
            }

            return [$entry, $sl, $tp];
        }

        // If sell: sl > entry > tp. Ensure sl - entry >= minDelta and entry - tp >= minDelta
        if ($sl > $entry && $tp < $entry) {
            if (($sl - $entry) < $minDelta) {
                $sl = $entry + $minDelta;
            }
            if (($entry - $tp) < $minDelta) {
                $tp = $entry - $minDelta;
            }

            return [$entry, $sl, $tp];
        }

        // Otherwise, just return as-is
        return [$entry, $sl, $tp];
    }
}
