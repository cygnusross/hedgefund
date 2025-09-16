<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class NewsStat extends Model
{
    use HasFactory;

    protected $table = 'news_stats';

    protected $fillable = [
        'pair_norm',
        'stat_date',
        'pos',
        'neg',
        'neu',
        'raw_score',
        'strength',
        'source',
        'fetched_at',
        'payload',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'fetched_at' => 'datetime',
        'payload' => 'array',
    ];

    /**
     * Upsert a news stat row from API data.
     * Expects array with keys: pair_norm, stat_date (YYYY-MM-DD), pos, neg, neu, raw_score, strength, payload(optional), source(optional), fetched_at(optional)
     */
    public static function upsertFromApi(array $stat): self
    {
        $pair = (string) (Arr::get($stat, 'pair_norm') ?? Arr::get($stat, 'pair') ?? '');
        $date = (string) (Arr::get($stat, 'stat_date') ?? Arr::get($stat, 'date') ?? '');

        if ($pair === '' || $date === '') {
            throw new \InvalidArgumentException('NewsStat::upsertFromApi requires pair_norm and stat_date');
        }

        $normalizedDate = Carbon::parse($date)->format('Y-m-d');

        $values = [
            'pair_norm' => $pair,
            'stat_date' => $normalizedDate,
            'pos' => (int) (Arr::get($stat, 'pos') ?? 0),
            'neg' => (int) (Arr::get($stat, 'neg') ?? 0),
            'neu' => (int) (Arr::get($stat, 'neu') ?? 0),
            'raw_score' => is_null(Arr::get($stat, 'raw_score')) ? null : (float) Arr::get($stat, 'raw_score'),
            'strength' => is_null(Arr::get($stat, 'strength')) ? null : (float) Arr::get($stat, 'strength'),
            'source' => (string) (Arr::get($stat, 'source') ?? 'forexnewsapi'),
            'fetched_at' => Arr::get($stat, 'fetched_at') ? Carbon::parse(Arr::get($stat, 'fetched_at')) : now(),
            'payload' => Arr::get($stat, 'payload') ? Arr::get($stat, 'payload') : null,
        ];

        // Try to find an existing record using whereDate (safer for sqlite/text date formats)
        return static::updateOrCreate(
            [
                'pair_norm' => $values['pair_norm'],
                'stat_date' => $values['stat_date'],
            ],
            $values
        );
    }
}
