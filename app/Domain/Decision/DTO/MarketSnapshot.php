<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class MarketSnapshot
{
    public function __construct(
        private string $status,
        private ?float $lastPrice,
        private ?float $atr5mPips,
        private ?float $spreadEstimatePips,
        private ?SentimentSnapshot $sentiment,
        private ?IgRulesSnapshot $igRules,
        private array $gateOverrides = [],
    ) {
    }

    /**
     * @param array<string, mixed> $market
     */
    public static function fromArray(array $market): self
    {
        $status = (string) ($market['status'] ?? 'UNKNOWN');
        $lastPrice = isset($market['last_price']) ? (float) $market['last_price'] : null;
        $atr5mPips = isset($market['atr5m_pips']) ? (float) $market['atr5m_pips'] : null;
        $spreadEstimatePips = isset($market['spread_estimate_pips']) ? (float) $market['spread_estimate_pips'] : null;

        $sentiment = null;
        if (isset($market['sentiment']) && is_array($market['sentiment'])) {
            $sentiment = SentimentSnapshot::fromArray($market['sentiment']);
        }

        $igRules = null;
        if (isset($market['ig_rules']) && is_array($market['ig_rules'])) {
            $igRules = IgRulesSnapshot::fromArray($market['ig_rules']);
        }

        $gateOverrides = [];
        if (isset($market['gate_overrides']) && is_array($market['gate_overrides'])) {
            foreach ($market['gate_overrides'] as $key => $value) {
                if (is_numeric($value)) {
                    $gateOverrides[$key] = (float) $value;
                }
            }
        }

        return new self($status, $lastPrice, $atr5mPips, $spreadEstimatePips, $sentiment, $igRules, $gateOverrides);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastPrice(): ?float
    {
        return $this->lastPrice;
    }

    public function atr5mPips(): ?float
    {
        return $this->atr5mPips;
    }

    public function spreadEstimatePips(): ?float
    {
        return $this->spreadEstimatePips;
    }

    public function sentiment(): ?SentimentSnapshot
    {
        return $this->sentiment;
    }

    public function igRules(): ?IgRulesSnapshot
    {
        return $this->igRules;
    }

    public function gateOverride(string $key): ?float
    {
        return $this->gateOverrides[$key] ?? null;
    }

    /**
     * @return array<string, float>
     */
    public function gateOverrides(): array
    {
        return $this->gateOverrides;
    }
}
