<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

use App\Domain\Market\FeatureSet;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ContextSnapshot
{
    public function __construct(
        private string $pair,
        private DateTimeImmutable $timestamp,
        private FeatureSet $features,
        private MarketSnapshot $market,
        private MetaSnapshot $meta,
        private CalendarSnapshot $calendar,
        private bool $isBlackout,
        private ?RulesSnapshot $rules = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $pair = (string) ($payload['pair'] ?? $payload['meta']['pair_norm'] ?? $payload['meta']['pair'] ?? '');

        $tsRaw = $payload['ts'] ?? null;
        if (is_string($tsRaw) && $tsRaw !== '') {
            try {
                $timestamp = new DateTimeImmutable($tsRaw, new DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        } else {
            $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $features = FeatureSetFactory::fromArray($timestamp, $payload['features'] ?? []);
        $market = MarketSnapshot::fromArray($payload['market'] ?? []);
        $meta = MetaSnapshot::fromArray($payload['meta'] ?? []);
        $calendar = CalendarSnapshot::fromArray($payload['calendar'] ?? []);
        $blackout = isset($payload['blackout']) ? (bool) $payload['blackout'] : $calendar->withinBlackout();

        $rulesPayload = $payload['rules'] ?? null;
        $rules = null;
        if (is_array($rulesPayload) && $rulesPayload !== []) {
            $rules = RulesSnapshot::fromArray($rulesPayload);
        }

        return new self($pair, $timestamp, $features, $market, $meta, $calendar, $blackout, $rules);
    }

    public function pair(): string
    {
        return $this->pair;
    }

    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function features(): FeatureSet
    {
        return $this->features;
    }

    public function market(): MarketSnapshot
    {
        return $this->market;
    }

    public function meta(): MetaSnapshot
    {
        return $this->meta;
    }

    public function calendar(): CalendarSnapshot
    {
        return $this->calendar;
    }

    public function isWithinBlackout(): bool
    {
        return $this->isBlackout;
    }

    public function rules(): ?RulesSnapshot
    {
        return $this->rules;
    }
}

final class FeatureSetFactory
{
    /**
     * @param  array<string, mixed>  $features
     */
    public static function fromArray(DateTimeImmutable $ts, array $features): FeatureSet
    {
        return new FeatureSet(
            $ts,
            (float) ($features['ema20'] ?? 0.0),
            (float) ($features['atr5m'] ?? 0.0),
            (float) ($features['ema20_z'] ?? 0.0),
            (float) ($features['recentRangePips'] ?? 0.0),
            (float) ($features['adx5m'] ?? 0.0),
            (string) ($features['trend30m'] ?? 'sideways'),
            is_array($features['supportLevels'] ?? null) ? $features['supportLevels'] : [],
            is_array($features['resistanceLevels'] ?? null) ? $features['resistanceLevels'] : [],
            isset($features['rsi14']) ? (float) $features['rsi14'] : null,
            isset($features['macd_line']) ? (float) $features['macd_line'] : null,
            isset($features['macd_signal']) ? (float) $features['macd_signal'] : null,
            isset($features['macd_histogram']) ? (float) $features['macd_histogram'] : null,
            isset($features['bb_upper']) ? (float) $features['bb_upper'] : null,
            isset($features['bb_middle']) ? (float) $features['bb_middle'] : null,
            isset($features['bb_lower']) ? (float) $features['bb_lower'] : null,
            isset($features['stoch_k']) ? (float) $features['stoch_k'] : null,
            isset($features['stoch_d']) ? (float) $features['stoch_d'] : null,
            isset($features['williamsR']) ? (float) $features['williamsR'] : null,
            isset($features['cci']) ? (float) $features['cci'] : null,
            isset($features['parabolicSAR']) ? (float) $features['parabolicSAR'] : null,
            isset($features['parabolicSARTrend']) ? (string) $features['parabolicSARTrend'] : null,
            isset($features['tr_upper']) ? (float) $features['tr_upper'] : null,
            isset($features['tr_middle']) ? (float) $features['tr_middle'] : null,
            isset($features['tr_lower']) ? (float) $features['tr_lower'] : null,
        );
    }
}
