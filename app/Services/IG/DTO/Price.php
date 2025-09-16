<?php

namespace App\Services\IG\DTO;

readonly class Price
{
    public function __construct(
        public ?float $ask = null,
        public ?float $bid = null,
        public ?float $lastTraded = null,
    ) {}

    /**
     * Create Price from API response array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ask: isset($data['ask']) ? (float) $data['ask'] : null,
            bid: isset($data['bid']) ? (float) $data['bid'] : null,
            lastTraded: isset($data['lastTraded']) ? (float) $data['lastTraded'] : null,
        );
    }
}
