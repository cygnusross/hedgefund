<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class MetaSnapshot
{
    public function __construct(
        private string $pairNorm,
        private ?int $dataAgeSec,
        private float $sleeveBalance,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromArray(array $meta): self
    {
        $pairNorm = (string) ($meta['pair_norm'] ?? $meta['pair'] ?? '');
        $dataAgeSec = isset($meta['data_age_sec']) ? (int) $meta['data_age_sec'] : null;
        $sleeveBalance = isset($meta['sleeve_balance']) ? (float) $meta['sleeve_balance'] : 10000.0;

        return new self($pairNorm, $dataAgeSec, $sleeveBalance);
    }

    public function pairNorm(): string
    {
        return $this->pairNorm;
    }

    public function dataAgeSec(): ?int
    {
        return $this->dataAgeSec;
    }

    public function sleeveBalance(): float
    {
        return $this->sleeveBalance;
    }
}

