<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class CalendarSnapshot
{
    public function __construct(private bool $withinBlackout)
    {
    }

    /**
     * @param array<string, mixed> $calendar
     */
    public static function fromArray(array $calendar): self
    {
        return new self((bool) ($calendar['within_blackout'] ?? false));
    }

    public function withinBlackout(): bool
    {
        return $this->withinBlackout;
    }
}

