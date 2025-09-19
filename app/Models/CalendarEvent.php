<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CalendarEvent extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'title',
        'currency',
        'impact',
        'event_time_utc',
        'source',
        'hash',
    ];

    protected $casts = [
        'event_time_utc' => 'datetime',
    ];

    /**
     * Generate a unique hash for deduplication.
     */
    public static function makeHash(string $title, string $currency, \DateTimeImmutable $eventTimeUtc): string
    {
        return md5(Str::lower(trim($title)).'|'.strtoupper($currency).'|'.$eventTimeUtc->format('c'));
    }

    /**
     * Scope for upcoming events (after now).
     */
    public function scopeUpcoming($query)
    {
        return $query->where('event_time_utc', '>', now()->toDateTimeString());
    }

    /**
     * Scope for high-impact events only.
     */
    public function scopeHighImpact($query)
    {
        return $query->where('impact', 'High');
    }

    /**
     * Helper: Create or update event from parsed feed.
     */
    public static function upsertFromFeed(array $data): self
    {
        $dt = new \DateTimeImmutable($data['event_time'], new \DateTimeZone('UTC'));

        return self::updateOrCreate(
            [
                'hash' => self::makeHash($data['title'], $data['currency'], $dt),
            ],
            [
                'title' => $data['title'],
                'currency' => strtoupper($data['currency']),
                'impact' => $data['impact'],
                'event_time_utc' => $dt,
                'source' => $data['source'] ?? null,
            ]
        );
    }
}
