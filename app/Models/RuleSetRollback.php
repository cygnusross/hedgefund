<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleSetRollback extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'rule_set_id',
        'rollback_trigger',
        'payload',
        'rolled_back_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'rolled_back_at' => 'datetime',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }
}
