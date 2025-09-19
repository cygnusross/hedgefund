<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class NewsSnapshot
{
    public function __construct(
        private ?string $direction,
        private float $strength,
    ) {
    }

    /**
     * @param array<string, mixed> $news
     */
    public static function fromArray(array $news): self
    {
        $direction = isset($news['direction']) ? (string) $news['direction'] : null;
        $strength = isset($news['strength']) ? (float) $news['strength'] : 0.0;

        return new self($direction, $strength);
    }

    public function direction(): ?string
    {
        return $this->direction;
    }

    public function strength(): float
    {
        return $this->strength;
    }
}

