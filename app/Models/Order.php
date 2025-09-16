<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'deal_reference',
        'currency_code',
        'direction',
        'epic',
        'expiry',
        'force_open',
        'good_till_date',
        'guaranteed_stop',
        'level',
        'limit_distance',
        'limit_level',
        'size',
        'stop_distance',
        'stop_level',
        'time_in_force',
        'type',
        'status',
        'open_cost',
        'close_cost',
    ];

    protected $casts = [
        'force_open' => 'boolean',
        'guaranteed_stop' => 'boolean',
        'good_till_date' => 'datetime',
        'level' => 'integer', // Raw format (11818)
        'limit_distance' => 'integer', // Raw points (24)
        'limit_level' => 'integer', // Raw format when used
        'size' => 'decimal:8',
        'stop_distance' => 'integer', // Raw points (24)
        'stop_level' => 'integer', // Raw format when used
        'open_cost' => 'decimal:2',
        'close_cost' => 'decimal:2',
    ];
}
