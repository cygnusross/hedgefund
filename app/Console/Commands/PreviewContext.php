<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Console\Command;

final class PreviewContext extends Command
{
    protected $signature = 'context:preview {pair} {--now=} {--days=1} {--force-news} {--force-calendar} {--force-sentiment} {--news-date=} {--news-fresh} {--force-spread}';

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
        $forceNews = (bool) $this->option('force-news');
        $forceCalendar = (bool) $this->option('force-calendar');

        // News options
        $newsDateOpt = $this->option('news-date'); // 'today'|'yesterday'|'YYYY-MM-DD' or null
        $newsFresh = (bool) $this->option('news-fresh');

        // Run refresh commands before building context if requested
        if ($forceNews) {
            $this->callSilent('news:refresh', ['pair' => $pair, '--days' => $days]);
        }

        if ($forceCalendar) {
            $this->callSilent('calendar:refresh', ['--force' => true]);
        }

        $ts = $nowOpt ? new \DateTimeImmutable($nowOpt, new \DateTimeZone('UTC')) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $forceSpread = (bool) $this->option('force-spread');
        $forceSentiment = (bool) $this->option('force-sentiment');

        $contextBuilder = app(ContextBuilder::class);

        $ctx = $contextBuilder->build($pair, $ts, $newsDateOpt ?? $days, $newsFresh, $forceSpread, ['force_sentiment' => $forceSentiment]);

        if ($ctx === null) {
            $this->line('Not enough warm-up.');

            return 0;
        }

        // $ctx is already the merged payload (features + news + calendar + blackout)
        $this->line(json_encode($ctx, JSON_PRETTY_PRINT));

        return 0;
    }
}
