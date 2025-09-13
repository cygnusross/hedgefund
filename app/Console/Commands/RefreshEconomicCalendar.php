<?php

namespace App\Console\Commands;

use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshEconomicCalendar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'economic:refresh-calendar {--force : Force refresh ignoring cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and cache the economic calendar';

    public function handle(EconomicCalendarProvider $provider): int
    {
        $lock = Cache::lock('economic_calendar_refresh_lock', 300);

        if (! $lock->get()) {
            $this->info('Another refresh is in progress. Exiting.');

            return self::SUCCESS;
        }

        try {
            // Fetch fresh data first
            $calendar = $provider->getCalendar(force: true);

            // On success, store to cache (provider may already cache, but ensure TTL is respected)
            $ttl = config('economic.cache_ttl', 1800);
            Cache::put('economic_calendar_this_week', $calendar, $ttl);

            $this->info('Economic calendar refreshed. Items: '.count($calendar));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to refresh economic calendar: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }
}
