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
        'is_active',
    ];

    protected $casts = [
        'currencies' => 'array',
        'is_active' => 'boolean',
    ];
}
