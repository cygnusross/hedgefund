<?php

namespace App\Services\Economic;

use App\Models\CalendarEvent;
use Illuminate\Support\Carbon;

final class DatabaseEconomicCalendarProvider implements EconomicCalendarProviderContract
{
    public function getCalendar(bool $force = false): array
    {
        if (! \Schema::hasTable('calendar_events')) {
            return [];
        }

        return CalendarEvent::query()
            ->where('event_time_utc', '>=', Carbon::now('UTC')->subDays(7))
            ->orderBy('event_time_utc')
            ->get()
            ->map(function (CalendarEvent $event) {
                return [
                    'title' => $event->title,
                    'country' => $event->currency,
                    'currency' => $event->currency,
                    'impact' => $event->impact,
                    'datetime' => $event->event_time_utc?->format(DATE_ATOM),
                    'source' => 'database',
                ];
            })
            ->toArray();
    }

    public function ingest(array $items): void
    {
        // No-op: data already expected in calendar_events table for backtests.
    }
}
