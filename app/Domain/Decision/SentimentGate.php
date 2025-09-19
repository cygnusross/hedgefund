<?php

namespace App\Domain\Decision;

use App\Domain\Rules\AlphaRules;
use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;
use Psr\Log\LoggerInterface;

final class SentimentGate
{
    public function __construct(private AlphaRules $rules, private ?LoggerInterface $logger = null) {}

    /**
     * Evaluate sentiment gate for a proposed action ('buy'|'sell') given sentiment payload.
     * Returns null when sentiment is not blocking. Returns an array ['blocked' => true, 'reasons' => array<string>]
     * when blocked (caller should set action to 'hold').
     */
    public function evaluate(?array $sentimentPayload, string $proposedAction): ?array
    {
        // If sentiment data unavailable, do not block
        if ($sentimentPayload === null) {
            return null;
        }

        $mode = $this->rules->getSentimentGate('mode', 'contrarian');
        $threshold = (int) $this->rules->getSentimentGate('contrarian_threshold_pct', 65);
        $neutralBand = $this->rules->getSentimentGate('neutral_band_pct', [45, 55]);

        // Validate payload shape (accept either long/short or long_pct/short_pct)
        $long = null;
        $short = null;
        if (isset($sentimentPayload['long']) && is_numeric($sentimentPayload['long'])) {
            $long = $this->normalizePercent($sentimentPayload['long']);
        }
        if (isset($sentimentPayload['short']) && is_numeric($sentimentPayload['short'])) {
            $short = $this->normalizePercent($sentimentPayload['short']);
        }
        if ($long === null && isset($sentimentPayload['long_pct']) && is_numeric($sentimentPayload['long_pct'])) {
            $long = $this->normalizePercent($sentimentPayload['long_pct']);
        }
        if ($short === null && isset($sentimentPayload['short_pct']) && is_numeric($sentimentPayload['short_pct'])) {
            $short = $this->normalizePercent($sentimentPayload['short_pct']);
        }

        if ($long === null || $short === null) {
            return null;
        }

        // Check neutral band (array with two ints [low, high])
        if (is_array($neutralBand) && count($neutralBand) === 2) {
            $low = (int) $neutralBand[0];
            $high = (int) $neutralBand[1];
            if ($long >= $low && $long <= $high && $short >= $low && $short <= $high) {
                return null; // too balanced
            }
        }

        // Determine dominant side
        if ($long >= $short) {
            $dominant = 'buy';
            $dominantPct = $long;
        } else {
            $dominant = 'sell';
            $dominantPct = $short;
        }

        // If mode is contrarian and dominant side matches proposed action, block when above threshold
        if ($mode === 'contrarian') {
            if ($dominant === $proposedAction && $dominantPct >= $threshold) {
                $reason = 'contrarian_crowd_'.($dominant === 'buy' ? 'long' : 'short');
                $this->log('info', 'sentiment_gate_blocked', ['reason' => $reason, 'dominant_pct' => $dominantPct, 'proposed' => $proposedAction]);

                return ['blocked' => true, 'reasons' => [$reason]];
            }
        }

        // Other modes (e.g., 'confirming') can be implemented later. Default: do not block.
        return null;
    }

    /**
     * Evaluate using explicit rules array (for unit tests or external callers).
     * Rules array keys: 'mode', 'contrarian_threshold_pct', 'neutral_band_pct'
     */
    public function evaluateWithRules(?array $sentimentPayload, string $proposedAction, array $rules): ?array
    {
        // If sentiment data unavailable, do not block
        if ($sentimentPayload === null) {
            return null;
        }

        $mode = $rules['mode'] ?? 'contrarian';
        $threshold = (int) ($rules['contrarian_threshold_pct'] ?? 65);
        $neutralBand = $rules['neutral_band_pct'] ?? [45, 55];

        // Normalize payload
        $long = null;
        $short = null;
        if (isset($sentimentPayload['long']) && is_numeric($sentimentPayload['long'])) {
            $long = $this->normalizePercent($sentimentPayload['long']);
        }
        if (isset($sentimentPayload['short']) && is_numeric($sentimentPayload['short'])) {
            $short = $this->normalizePercent($sentimentPayload['short']);
        }
        if ($long === null && isset($sentimentPayload['long_pct']) && is_numeric($sentimentPayload['long_pct'])) {
            $long = $this->normalizePercent($sentimentPayload['long_pct']);
        }
        if ($short === null && isset($sentimentPayload['short_pct']) && is_numeric($sentimentPayload['short_pct'])) {
            $short = $this->normalizePercent($sentimentPayload['short_pct']);
        }

        if ($long === null || $short === null) {
            return null;
        }

        // Check neutral band
        if (is_array($neutralBand) && count($neutralBand) === 2) {
            $low = (int) $neutralBand[0];
            $high = (int) $neutralBand[1];
            if ($long >= $low && $long <= $high && $short >= $low && $short <= $high) {
                return null;
            }
        }

        $dominant = $long >= $short ? 'buy' : 'sell';
        $dominantPct = $long >= $short ? $long : $short;

        if ($mode === 'contrarian') {
            if ($dominant === $proposedAction && $dominantPct >= $threshold) {
                $reason = 'contrarian_crowd_'.($dominant === 'buy' ? 'long' : 'short');
                $this->log('info', 'sentiment_gate_blocked', ['reason' => $reason, 'dominant_pct' => $dominantPct, 'proposed' => $proposedAction]);

                return ['blocked' => true, 'reasons' => [$reason]];
            }
        }

        return null;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->{$level}($message, $context);

            return;
        }

        try {
            \Illuminate\Support\Facades\Log::{$level}($message, $context);
        } catch (\Throwable $e) {
            // In test contexts the Log facade may not be available; ignore
        }
    }

    private function normalizePercent(int|float|string $value): int
    {
        $decimal = Decimal::of($value);
        $clamped = $decimal;

        $zero = Decimal::of(0);
        $hundred = Decimal::of(100);

        if ($clamped->isLessThan($zero)) {
            $clamped = $zero;
        }

        if ($clamped->isGreaterThan($hundred)) {
            $clamped = $hundred;
        }

        return (int) $clamped->toScale(0, RoundingMode::HALF_UP)->toInt();
    }
}
