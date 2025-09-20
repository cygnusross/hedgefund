<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleSetRegime extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_set_id',
        'week_tag',
        'metrics',
    ];

    protected $casts = [
        'metrics' => 'array',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }
}
