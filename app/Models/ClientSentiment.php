<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientSentiment extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'pair',
        'long_pct',
        'short_pct',
        'recorded_at',
    ];

    protected $casts = [
        'long_pct' => 'decimal:2',
        'short_pct' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];
}
