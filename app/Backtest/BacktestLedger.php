<?php

namespace App\Backtest;

use App\Domain\Execution\PositionLedgerContract;
use DateTimeImmutable;

final class BacktestLedger implements PositionLedgerContract
{
    private array $openPositions = [];

    private array $closedPositions = [];

    private ?array $lastTrade = null;

    public function todaysPnLPct(): float
    {
        return array_sum(array_map(fn ($trade) => $trade['pnl_pct'], $this->closedPositions));
    }

    public function lastTrade(): ?array
    {
        return $this->lastTrade;
    }

    public function openPositionsCount(): int
    {
        return array_sum(array_map('count', $this->openPositions));
    }

    public function pairExposurePct(string $pair): float
    {
        return array_sum(array_map(fn ($position) => $position['risk_pct'], $this->openPositions[$pair] ?? []));
    }

    public function openPosition(string $pair, array $position): void
    {
        if (! isset($this->openPositions[$pair])) {
            $this->openPositions[$pair] = [];
        }
        $this->openPositions[$pair][$position['id']] = $position;
    }

    public function closePosition(string $pair, string $positionId, string $outcome, float $pnlPct, DateTimeImmutable $ts): void
    {
        if (! isset($this->openPositions[$pair][$positionId])) {
            return;
        }

        $position = $this->openPositions[$pair][$positionId];
        unset($this->openPositions[$pair][$positionId]);

        $trade = [
            'pair' => $pair,
            'position' => $position,
            'outcome' => $outcome,
            'pnl_pct' => $pnlPct,
            'closed_at' => $ts,
        ];

        $this->closedPositions[] = $trade;
        $this->lastTrade = ['outcome' => $outcome, 'ts' => $ts];
    }

    public function trades(): array
    {
        return $this->closedPositions;
    }

    public function positionsForPair(string $pair): array
    {
        return $this->openPositions[$pair] ?? [];
    }

    public function openPositions(): array
    {
        return $this->openPositions;
    }
}
