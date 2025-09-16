<?php

namespace App\Services\IG\DTO;

readonly class HistoricalPrice
{
    public function __construct(
        public Price $closePrice,
        public Price $highPrice,
        public Price $lowPrice,
        public Price $openPrice,
        public string $snapshotTime,
        public ?float $lastTradedVolume = null,
    ) {}

    /**
     * Create HistoricalPrice from API response array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            closePrice: Price::fromArray($data['closePrice'] ?? []),
            highPrice: Price::fromArray($data['highPrice'] ?? []),
            lowPrice: Price::fromArray($data['lowPrice'] ?? []),
            openPrice: Price::fromArray($data['openPrice'] ?? []),
            snapshotTime: $data['snapshotTime'] ?? '',
            lastTradedVolume: isset($data['lastTradedVolume']) ? (float) $data['lastTradedVolume'] : null,
        );
    }

    /**
     * Convert to Bar object using bid prices for OHLC
     */
    public function toBar(): \App\Domain\Market\Bar
    {
        // Parse the snapshot time (format: yyyy/MM/dd hh:mm:ss)
        $timestamp = \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $this->snapshotTime, new \DateTimeZone('UTC'));

        if (!$timestamp) {
            throw new \InvalidArgumentException("Invalid snapshot time format: {$this->snapshotTime}");
        }

        // Use bid prices for OHLC as they represent the price traders can sell at
        return new \App\Domain\Market\Bar(
            ts: $timestamp,
            open: $this->openPrice->bid ?? 0.0,
            high: $this->highPrice->bid ?? 0.0,
            low: $this->lowPrice->bid ?? 0.0,
            close: $this->closePrice->bid ?? 0.0,
            volume: $this->lastTradedVolume,
        );
    }
}
