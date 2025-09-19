<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class DecisionResult
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        private string $action,
        private float $confidence,
        private array $reasons,
        private bool $blocked,
        private ?float $size = null,
        private ?float $riskPct = null,
        private ?float $entry = null,
        private ?float $stopLoss = null,
        private ?float $takeProfit = null,
    ) {}

    public function action(): string
    {
        return $this->action;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return array<int, string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function size(): ?float
    {
        return $this->size;
    }

    public function riskPct(): ?float
    {
        return $this->riskPct;
    }

    public function entry(): ?float
    {
        return $this->entry;
    }

    public function stopLoss(): ?float
    {
        return $this->stopLoss;
    }

    public function takeProfit(): ?float
    {
        return $this->takeProfit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'action' => $this->action,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'blocked' => $this->blocked,
        ];

        if ($this->size !== null) {
            $payload['size'] = $this->size;
        }

        if ($this->riskPct !== null) {
            $payload['risk_pct'] = $this->riskPct;
        }

        if ($this->entry !== null) {
            $payload['entry'] = $this->entry;
        }

        if ($this->stopLoss !== null) {
            $payload['sl'] = $this->stopLoss;
        }

        if ($this->takeProfit !== null) {
            $payload['tp'] = $this->takeProfit;
        }

        return $payload;
    }
}
