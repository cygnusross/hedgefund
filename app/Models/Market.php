<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'symbol',
        'epic',
        'currencies',
        'price_scale',
        'atr_min_pips_override',
        'adx_min_override',
        'z_abs_max_override',
        'is_active',
    ];

    protected $casts = [
        'currencies' => 'array',
        'is_active' => 'boolean',
        'price_scale' => 'float',
        'atr_min_pips_override' => 'float',
        'adx_min_override' => 'float',
        'z_abs_max_override' => 'float',
    ];
}
