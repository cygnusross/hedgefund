<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class SentimentSnapshot
{
    public function __construct(
        private ?float $longPct,
        private ?float $shortPct,
    ) {
    }

    /**
     * @param array<string, mixed> $sentiment
     */
    public static function fromArray(array $sentiment): self
    {
        $longPct = isset($sentiment['long_pct']) ? (float) $sentiment['long_pct'] : null;
        $shortPct = isset($sentiment['short_pct']) ? (float) $sentiment['short_pct'] : null;

        return new self($longPct, $shortPct);
    }

    public function longPct(): ?float
    {
        return $this->longPct;
    }

    public function shortPct(): ?float
    {
        return $this->shortPct;
    }
}

