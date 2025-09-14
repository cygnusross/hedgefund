<?php

namespace App\Domain\Execution;

interface PositionLedgerContract
{
    /**
     * Return today's realized PnL as percent (e.g. -2.5 for -2.5%)
     */
    public function todaysPnLPct(): float;

    /**
     * Return last trade info or null. Expected shape: ['outcome' => 'win'|'loss', 'ts' => DateTimeImmutable]
     *
     * @return array<string,mixed>|null
     */
    public function lastTrade(): ?array;

    /**
     * Return the current number of open positions across the account.
     */
    public function openPositionsCount(): int;

    /**
     * Return exposure percent for a given normalized pair (0-100). For example 12.5 means 12.5%.
     */
    public function pairExposurePct(string $pair): float;
}
