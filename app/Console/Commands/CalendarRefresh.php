<?php

namespace App\Console\Commands;

use App\Services\Economic\EconomicCalendarProviderContract;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Console\Command;

// ...existing imports...

final class CalendarRefresh extends Command
{
    protected $signature = 'calendar:refresh {--force}';

    protected $description = 'Force-refresh the economic calendar and cache results.';

    public function __construct(protected EconomicCalendarProviderContract $provider)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        try {
            $items = $this->provider->getCalendar($force);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch economic calendar: '.$e->getMessage());

            return 1;
        }

        // Persist to DB using provider ingest helper (model upsert)
        try {
            if (method_exists($this->provider, 'ingest')) {
                $this->provider->ingest($items);
            }
        } catch (\Throwable $e) {
            $this->error('Failed to persist calendar items: '.$e->getMessage());

            return 1;
        }

        // Output first 5 upcoming events with date, country, impact
        $count = 0;
        foreach ($items as $it) {
            if ($count >= 5) {
                break;
            }

            $date = $it['date_utc'] ?? null;
            $country = $it['country'] ?? ($it['currency'] ?? '');
            $impact = $it['impact'] ?? null;

            $dateStr = $date;
            if ($date) {
                try {
                    $dt = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
                    $dateStr = $dt->format(\DateTimeImmutable::ATOM);
                } catch (\Throwable $e) {
                    // leave as-is
                }
            }

            $this->line(sprintf('%s | %s | %s', $dateStr, $country, $impact));
            $count++;
        }

        $this->info(sprintf('Persisted %d calendar items into calendar_events table.', count($items)));

        return 0;
    }
}
