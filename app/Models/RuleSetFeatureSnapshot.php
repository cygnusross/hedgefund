<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleSetFeatureSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_set_id',
        'market',
        'feature_hash',
        'storage_path',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }
}
