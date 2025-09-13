<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
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
        'level' => 'decimal:5',
        'limit_distance' => 'decimal:2',
        'limit_level' => 'decimal:5',
        'size' => 'decimal:8',
        'stop_distance' => 'decimal:2',
        'stop_level' => 'decimal:5',
        'open_cost' => 'decimal:2',
        'close_cost' => 'decimal:2',
    ];
}
