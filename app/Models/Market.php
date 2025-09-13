<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    protected $fillable = [
        'name',
        'symbol',
        'epic',
        'currencies',
        'is_active',
    ];

    protected $casts = [
        'currencies' => 'array',
        'is_active' => 'boolean',
    ];
}
