<?php

namespace App\Domain\Execution;

final class NullPositionLedger implements PositionLedgerContract
{
    public function todaysPnLPct(): float
    {
        return 0.0;
    }

    public function lastTrade(): ?array
    {
        return null;
    }

    public function openPositionsCount(): int
    {
        return 0;
    }

    public function pairExposurePct(string $pair): float
    {
        return 0.0;
    }
}
