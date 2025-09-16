<?php

namespace App\Services\IG\DTO;

readonly class Allowance
{
    public function __construct(
        public int $allowanceExpiry,
        public int $remainingAllowance,
        public int $totalAllowance,
    ) {}

    /**
     * Create Allowance from API response array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            allowanceExpiry: (int) ($data['allowanceExpiry'] ?? 0),
            remainingAllowance: (int) ($data['remainingAllowance'] ?? 0),
            totalAllowance: (int) ($data['totalAllowance'] ?? 0),
        );
    }
}
