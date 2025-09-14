<?php

namespace App\Console\Commands;

use App\Application\News\NewsStatIngestor;
use App\Models\Market;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NewsIngestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // Note: previously this command accepted a positional `pair` argument.
    // The provider now always disables upstream cache (cache=false), so the
    // deprecated `--fresh` option has been removed. The positional argument
    // is still supported for backwards compatibility.
    protected $signature = 'news:ingest {pair?} {--pair=} {--today} {--from=} {--to=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest news stats from ForexNewsApi into news_stats table';

    public function handle(NewsStatIngestor $ingestor): int
    {
        // Prefer explicit --pair option; fall back to positional arg for
        // backwards compatibility.
        $pair = $this->option('pair') ?: $this->argument('pair');
        $pair = $pair ? (string) $pair : null;
        $isToday = $this->option('today');
        $from = $this->option('from');
        $to = $this->option('to');

        // If no pair provided, collect active market symbols and call the
        // ingestor once with the array of pairs so the provider receives a
        // single API call per page with comma-separated currencypair values.
        if (! $pair) {
            $markets = Market::where('is_active', true)->get();
            $symbols = $markets->pluck('symbol')->filter()->values()->all();

            if (empty($symbols)) {
                $this->info('No active markets found to ingest.');

                return 0;
            }

            // Default to today if no range provided
            if ($isToday || (! $from && ! $to)) {
                $this->info('Ingesting today for all active markets...');
                $rows = $ingestor->ingestToday($symbols);
                $this->info("Finished ingest for active markets — total upserts: {$rows}");

                return 0;
            }

            if ($from && $to) {
                try {
                    $f = Carbon::parse($from, 'UTC')->format('Y-m-d');
                    $t = Carbon::parse($to, 'UTC')->format('Y-m-d');
                } catch (\Throwable $e) {
                    $this->error('Invalid --from or --to date; please use YYYY-MM-DD');

                    return 1;
                }

                $this->info("Ingesting range for all active markets from {$f} to {$t}...");
                $rows = $ingestor->ingestRangeDates($symbols, $f, $t);
                $this->info("Finished ingest for active markets — total upserts: {$rows}");

                return 0;
            }

            $this->error('Usage: news:ingest --today OR news:ingest --from=YYYY-MM-DD --to=YYYY-MM-DD');

            return 1;
        }

        // Single pair flow
        // Default to today when no range provided
        if ($isToday || (! $from && ! $to)) {
            $this->info("Ingesting today for pair {$pair}...");
            $rows = $ingestor->ingestToday($pair);
            $this->info("Completed ingestToday for {$pair}: upserted {$rows} rows");

            return 0;
        }

        if ($from && $to) {
            try {
                $f = Carbon::parse($from, 'UTC')->format('Y-m-d');
                $t = Carbon::parse($to, 'UTC')->format('Y-m-d');
            } catch (\Throwable $e) {
                $this->error('Invalid --from or --to date; please use YYYY-MM-DD');

                return 1;
            }

            $this->info("Ingesting range for pair {$pair} from {$f} to {$t}...");
            $rows = $ingestor->ingestRangeDates($pair, $f, $t);
            $this->info("Completed ingestRange for {$pair}: upserted {$rows} rows");

            return 0;
        }

        $this->error('Usage: news:ingest {pair?} --today OR news:ingest {pair?} --from=YYYY-MM-DD --to=YYYY-MM-DD');

        return 1;
    }
}
