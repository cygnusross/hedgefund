<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag',
        'period_start',
        'period_end',
        'base_rules',
        'market_overrides',
        'emergency_overrides',
        'metrics',
        'shadow_mode',
        'activated_at',
        'deactivated_at',
        'risk_bands',
        'regime_snapshot',
        'provenance',
        'model_artifacts',
        'feature_hash',
        'mc_seed',
        'is_active',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'base_rules' => 'array',
        'market_overrides' => 'array',
        'emergency_overrides' => 'array',
        'metrics' => 'array',
        'risk_bands' => 'array',
        'regime_snapshot' => 'array',
        'provenance' => 'array',
        'model_artifacts' => 'array',
        'shadow_mode' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Activate this rule set for production. Deactivates any currently active set.
     */
    public function activate(): void
    {
        static::query()->where('is_active', true)->where('id', '!=', $this->id)
            ->update(['is_active' => false, 'deactivated_at' => now()]);

        $this->update([
            'is_active' => true,
            'activated_at' => now(),
            'shadow_mode' => false,
        ]);
    }

    /**
     * Activate this rule set in shadow mode (observation). Does not deactivate existing production rules.
     */
    public function activateShadow(): void
    {
        $this->update([
            'is_active' => true,
            'activated_at' => now(),
            'shadow_mode' => true,
        ]);
    }

    public function regimes(): HasMany
    {
        return $this->hasMany(RuleSetRegime::class);
    }

    public function featureSnapshots(): HasMany
    {
        return $this->hasMany(RuleSetFeatureSnapshot::class);
    }

    public function rollbacks(): HasMany
    {
        return $this->hasMany(RuleSetRollback::class);
    }
}
