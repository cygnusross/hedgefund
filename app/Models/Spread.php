<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Spread extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair',
        'spread_pips',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'spread_pips' => 'decimal:5',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Scope to filter by trading pair
     */
    public function scopeForPair($query, string $pair)
    {
        return $query->where('pair', $pair);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeBetweenDates($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('recorded_at', [$start, $end]);
    }

    /**
     * Get the latest spread for a pair
     */
    public static function getLatestFor(string $pair): ?self
    {
        return static::forPair($pair)
            ->latest('recorded_at')
            ->first();
    }

    /**
     * Get average spread for a pair within date range
     */
    public static function getAverageFor(string $pair, Carbon $start, Carbon $end): ?float
    {
        $average = static::forPair($pair)
            ->betweenDates($start, $end)
            ->avg('spread_pips');

        return $average ? (float) $average : null;
    }
}
