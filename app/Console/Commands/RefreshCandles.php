<?php

namespace App\Console\Commands;

use App\Application\Candles\IncrementalCandleUpdater;
use Illuminate\Console\Command;

class RefreshCandles extends Command
{
    protected $signature = 'candles:refresh {pair} {--interval=5min}';

    protected $description = 'Refresh cached candles for a symbol/interval using the incremental updater';

    public function handle(IncrementalCandleUpdater $updater): int
    {
        $pair = $this->argument('pair');
        $interval = $this->option('interval');

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
