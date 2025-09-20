<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Console\Command;

final class PreviewContext extends Command
{
    protected $signature = 'context:preview {pair} {--now=} {--days=1} {--force-calendar} {--force-sentiment} {--force-spread}';

    protected $description = 'Preview the decision context JSON for a pair at a given time.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pair = (string) $this->argument('pair');
        $nowOpt = $this->option('now');
        $days = (int) $this->option('days');

        // Optional force-refresh flags
        $forceCalendar = (bool) $this->option('force-calendar');

        if ($forceCalendar) {
            $this->call('calendar:refresh', ['--force' => true]);
        }

        $ts = $nowOpt ? new \DateTimeImmutable($nowOpt, new \DateTimeZone('UTC')) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $forceSpread = (bool) $this->option('force-spread');
        $forceSentiment = (bool) $this->option('force-sentiment');

        $contextBuilder = app(ContextBuilder::class);

        // Use false for $fresh to avoid forcing candle refresh
        $ctx = $contextBuilder->build($pair, $ts, false, $forceSpread, ['force_sentiment' => $forceSentiment]);

        if ($ctx === null) {
            $this->line('Not enough warm-up.');

            return 0;
        }

        // $ctx is already the merged payload (features + sentiment + calendar + blackout)
        $this->line(json_encode($ctx, JSON_PRETTY_PRINT));

        return 0;
    }
}
