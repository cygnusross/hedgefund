<?php

declare(strict_types=1);

namespace App\Domain\FX;

final class PipMath
{
    /**
     * Return pip size for the given pair (quote currency determines precision).
     */
    public static function pipSize(string $pair): float
    {
        $parts = preg_split('/[\/\-]/', $pair);
        $quote = isset($parts[1]) ? strtoupper($parts[1]) : '';

        if ($quote === 'JPY') {
            return 0.01;
        }

        return 0.0001;
    }

    public static function toPips(float $priceDelta, string $pair): float
    {
        return $priceDelta / self::pipSize($pair);
    }

    public static function fromPips(float $pips, string $pair): float
    {
        return $pips * self::pipSize($pair);
    }

    /**
     * Tick size is identical to pip size for FX pairs in this project.
     */
    public static function tickSize(string $pair): float
    {
        return self::pipSize($pair);
    }

    /**
     * Provide a conservative spread estimate in pips for a given pair.
     * These are simple defaults and can be overridden in future by config.
     */
    public static function spreadEstimatePips(string $pair): float
    {
        $norm = strtoupper(str_replace(['/', '-'], '-', $pair));

        return match ($norm) {
            'EUR-USD' => 0.8,
            'GBP-USD' => 1.2,
            'USD-JPY' => 0.8,
            'AUD-USD' => 1.0,
            'USD-CHF' => 1.0,
            default => 1.0,
        };
    }
}
