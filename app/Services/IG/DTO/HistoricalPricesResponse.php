<?php

namespace App\Services\IG\DTO;

readonly class HistoricalPricesResponse
{
    public function __construct(
        public Allowance $allowance,
        public string $instrumentType,
        /** @var HistoricalPrice[] */
        public array $prices,
    ) {}

    /**
     * Create HistoricalPricesResponse from API response array
     */
    public static function fromArray(array $data): self
    {
        $prices = [];
        foreach ($data['prices'] ?? [] as $priceData) {
            $prices[] = HistoricalPrice::fromArray($priceData);
        }

        return new self(
            allowance: Allowance::fromArray($data['allowance'] ?? []),
            instrumentType: $data['instrumentType'] ?? 'UNKNOWN',
            prices: $prices,
        );
    }

    /**
     * Convert all historical prices to Bar objects
     *
     * @return \App\Domain\Market\Bar[]
     */
    public function toBars(): array
    {
        return array_map(fn (HistoricalPrice $price) => $price->toBar(), $this->prices);
    }
}
