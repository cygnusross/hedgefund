<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'name',
        'type',
        'balance',
        'initial_balance',
        'available_balance',
        'used_margin',
        'currency',
        'is_active',
        'max_risk_per_trade_pct',
        'max_portfolio_risk_pct',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'initial_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'used_margin' => 'decimal:2',
            'max_risk_per_trade_pct' => 'decimal:2',
            'max_portfolio_risk_pct' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Calculate available balance (balance minus used margin)
     */
    public function calculateAvailableBalance(): float
    {
        return (float) ($this->balance - $this->used_margin);
    }

    /**
     * Check if the account has sufficient available balance for a trade
     */
    public function hasAvailableBalance(float $requiredAmount): bool
    {
        return $this->calculateAvailableBalance() >= $requiredAmount;
    }

    /**
     * Reserve margin for a trade
     */
    public function reserveMargin(float $amount): bool
    {
        if (! $this->hasAvailableBalance($amount)) {
            return false;
        }

        $this->used_margin += $amount;
        $this->available_balance = $this->calculateAvailableBalance();
        $this->save();

        return true;
    }

    /**
     * Release reserved margin
     */
    public function releaseMargin(float $amount): void
    {
        $this->used_margin = max(0, $this->used_margin - $amount);
        $this->available_balance = $this->calculateAvailableBalance();
        $this->save();
    }

    /**
     * Calculate maximum position size based on risk percentage
     */
    public function maxPositionSizeForRisk(float $stopLossPips, float $pipValue): float
    {
        $maxRiskAmount = $this->balance * ($this->max_risk_per_trade_pct / 100);

        return $maxRiskAmount / ($stopLossPips * $pipValue);
    }

    /**
     * Get the current profit/loss vs initial balance
     */
    protected function profitLoss(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balance - $this->initial_balance
        );
    }

    /**
     * Get the return percentage
     */
    protected function returnPercentage(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->initial_balance > 0
                ? (($this->balance - $this->initial_balance) / $this->initial_balance) * 100
                : 0
        );
    }

    /**
     * Scope for active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for trading accounts
     */
    public function scopeTrading($query)
    {
        return $query->where('type', 'trading');
    }
}
