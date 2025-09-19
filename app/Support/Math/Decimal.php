<?php

declare(strict_types=1);

namespace App\Support\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Decimal
{
    private const DEFAULT_SCALE = 12;

    public static function of(int|float|string|BigDecimal $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            return BigDecimal::of((string) $value);
        }

        if (is_string($value)) {
            return BigDecimal::of($value);
        }

        $formatted = number_format($value, self::DEFAULT_SCALE, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        if ($formatted === '' || $formatted === '-0') {
            $formatted = '0';
        }

        return BigDecimal::of($formatted);
    }

    public static function toFloat(BigDecimal $decimal, int $scale = 10): float
    {
        $scaled = $decimal->toScale($scale, RoundingMode::HALF_UP);

        return $scaled->toFloat();
    }

    public static function isZero(BigDecimal $decimal): bool
    {
        return $decimal->isZero();
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }
}
