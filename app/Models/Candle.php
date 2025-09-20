<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair',
        'interval',
        'timestamp',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'open' => 'decimal:5',
            'high' => 'decimal:5',
            'low' => 'decimal:5',
            'close' => 'decimal:5',
            'volume' => 'integer',
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
     * Scope to filter by interval
     */
    public function scopeForInterval($query, string $interval)
    {
        return $query->where('interval', $interval);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeBetweenDates($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('timestamp', [$start, $end]);
    }

    /**
     * Get the latest candle for a pair/interval combination
     */
    public static function getLatestFor(string $pair, string $interval): ?self
    {
        return static::forPair($pair)
            ->forInterval($interval)
            ->latest('timestamp')
            ->first();
    }

    /**
     * Get candles in the correct format for ContextBuilder
     * Returns array of Bar objects
     */
    public function toBar(): \App\Domain\Market\Bar
    {
        return new \App\Domain\Market\Bar(
            new \DateTimeImmutable($this->timestamp->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')),
            (float) $this->open,
            (float) $this->high,
            (float) $this->low,
            (float) $this->close,
            $this->volume ? (float) $this->volume : null
        );
    }

    /**
     * Convert collection of candles to Bar array for ContextBuilder
     */
    public static function toBars($candles): array
    {
        return $candles->map(fn ($candle) => $candle->toBar())->toArray();
    }
}
