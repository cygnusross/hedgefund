<?php

namespace App\Console\Commands;

use App\Application\Candles\IncrementalCandleUpdater;
use App\Models\Market;
use Illuminate\Console\Command;

class RefreshCandles extends Command
{
    protected $signature = 'candles:refresh {pair?} {--interval=5min}';

    protected $description = 'Refresh cached candles for a symbol/interval using the incremental updater. If no pair is provided, refreshes all active markets.';

    public function handle(IncrementalCandleUpdater $updater): int
    {
        $pair = $this->argument('pair');
        $interval = $this->option('interval');

        // If no pair specified, get all active markets
        if (! $pair) {
            $pairs = Market::where('is_active', true)->pluck('symbol')->toArray();

            if (empty($pairs)) {
                $this->error('No active markets found');

                return 1;
            }

            $this->info("Refreshing candles for all active markets ({$interval}):");

            $successCount = 0;
            $failureCount = 0;

            foreach ($pairs as $marketPair) {
                $this->line("Processing {$marketPair}...");

                try {
                    $updater->sync($marketPair, $interval, 500);
                    $successCount++;
                    $this->info("✓ {$marketPair}");
                } catch (\Throwable $e) {
                    $failureCount++;
                    $this->error("✗ {$marketPair}: {$e->getMessage()}");
                }
            }

            $this->info("Complete: {$successCount} successful, {$failureCount} failed");

            return $failureCount > 0 ? 1 : 0;
        }

        // Single pair mode (original behavior)
        $this->info("Refreshing candles for {$pair} {$interval}");

        try {
            $updater->sync($pair, $interval, 500);
            $this->info('Done');

            return 0;
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            return 1;
        }
    }
}
