<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use Carbon\CarbonImmutable;

class CalibrationConfig
{
    public function __construct(
        public readonly string $tag,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly bool $dryRun = false,
        public readonly bool $activate = true,
        public readonly bool $shadowMode = false,
        public readonly array $markets = [],
        public readonly bool $activateFlag = false,
        public readonly ?string $baselineTag = null,
        public readonly int $calibrationWindowDays = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): self
    {
        $tag = isset($options['tag']) && is_string($options['tag']) && $options['tag'] !== ''
            ? $options['tag']
            : self::nextIsoWeekTag();

        // Check if custom period dates are provided
        if (isset($options['period_start']) && isset($options['period_end'])) {
            $start = CarbonImmutable::parse($options['period_start'])->startOfDay();
            $end = CarbonImmutable::parse($options['period_end'])->endOfDay();
        } else {
            // Derive from ISO week tag if it's a valid ISO week format
            if (preg_match('/^(\d{4})-W(\d{2})$/', $tag)) {
                [$start, $end] = self::periodForTag($tag);
            } else {
                // For non-ISO week tags, use current week as default
                $now = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);
                $start = $now->startOfDay();
                $end = $now->addDays(6)->endOfDay();
            }
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $shadow = (bool) ($options['shadow'] ?? false);
        $activate = array_key_exists('activate_flag', $options) ? (bool) ($options['activate_flag'] ?? false) : (bool) ($options['activate'] ?? true);
        $markets = [];
        if (! empty($options['markets']) && is_string($options['markets'])) {
            $markets = array_map('trim', explode(',', $options['markets']));
        }
        $baselineTag = isset($options['baseline_tag']) && is_string($options['baseline_tag']) && $options['baseline_tag'] !== ''
            ? $options['baseline_tag']
            : null;

        if ($dryRun) {
            $activate = false;
        }

        return new self(
            tag: $tag,
            periodStart: $start,
            periodEnd: $end,
            dryRun: $dryRun,
            activate: $activate,
            shadowMode: $shadow,
            markets: $markets,
            activateFlag: $activate,
            baselineTag: $baselineTag,
        );
    }

    private static function nextIsoWeekTag(): string
    {
        $now = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);
        $nextWeek = $now->addWeek();

        return sprintf('%d-W%02d', $nextWeek->isoWeekYear, $nextWeek->isoWeek);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function periodForTag(string $tag): array
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $tag, $matches)) {
            throw new \InvalidArgumentException("Invalid ISO week tag: {$tag}");
        }

        [$year, $week] = [(int) $matches[1], (int) $matches[2]];
        $start = CarbonImmutable::now('UTC')->setISODate($year, $week, 1)->startOfDay();
        $end = $start->addDays(6)->endOfDay();

        return [$start, $end];
    }
}
