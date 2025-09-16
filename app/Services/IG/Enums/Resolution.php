<?php

namespace App\Services\IG\Enums;

enum Resolution: string
{
    case MINUTE = 'MINUTE';
    case MINUTE_2 = 'MINUTE_2';
    case MINUTE_3 = 'MINUTE_3';
    case MINUTE_5 = 'MINUTE_5';
    case MINUTE_10 = 'MINUTE_10';
    case MINUTE_15 = 'MINUTE_15';
    case MINUTE_30 = 'MINUTE_30';
    case HOUR = 'HOUR';
    case HOUR_2 = 'HOUR_2';
    case HOUR_3 = 'HOUR_3';
    case HOUR_4 = 'HOUR_4';
    case DAY = 'DAY';
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';

    /**
     * Convert IG resolution to internal interval string
     */
    public function toInterval(): string
    {
        return match ($this) {
            self::MINUTE => '1min',
            self::MINUTE_2 => '2min',
            self::MINUTE_3 => '3min',
            self::MINUTE_5 => '5min',
            self::MINUTE_10 => '10min',
            self::MINUTE_15 => '15min',
            self::MINUTE_30 => '30min',
            self::HOUR => '1h',
            self::HOUR_2 => '2h',
            self::HOUR_3 => '3h',
            self::HOUR_4 => '4h',
            self::DAY => '1d',
            self::WEEK => '1w',
            self::MONTH => '1M',
        };
    }

    /**
     * Convert internal interval string to IG resolution
     */
    public static function fromInterval(string $interval): self
    {
        return match ($interval) {
            '1min' => self::MINUTE,
            '2min' => self::MINUTE_2,
            '3min' => self::MINUTE_3,
            '5min' => self::MINUTE_5,
            '10min' => self::MINUTE_10,
            '15min' => self::MINUTE_15,
            '30min' => self::MINUTE_30,
            '1h' => self::HOUR,
            '2h' => self::HOUR_2,
            '3h' => self::HOUR_3,
            '4h' => self::HOUR_4,
            '1d' => self::DAY,
            '1w' => self::WEEK,
            '1M' => self::MONTH,
            default => throw new \InvalidArgumentException("Unsupported interval: {$interval}"),
        };
    }

    /**
     * Get all supported resolutions
     *
     * @return Resolution[]
     */
    public static function all(): array
    {
        return self::cases();
    }
}
