<?php

namespace App\Console\Commands;

use App\Services\News\NewsProvider;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Console\Command;

final class NewsRefresh extends Command
{
    protected $signature = 'news:refresh {pair} {--days=1}';

    protected $description = 'Force-fetch stats for a pair from the news provider (stats-only).';

    public function __construct(protected NewsProvider $provider)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pair = (string) $this->argument('pair');
        $days = max(1, (int) $this->option('days'));

        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $results = [];

        for ($i = 0; $i < $days; $i++) {
            $d = $today->sub(new \DateInterval('P'.$i.'D'))->format('Y-m-d');
            try {
                if (method_exists($this->provider, 'fetchStat')) {
                    $stat = $this->provider->fetchStat($pair, $d, true);
                    if (is_array($stat) && ! empty($stat)) {
                        $results[$d] = $stat;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn(sprintf('fetchStat failed for %s on %s: %s', $pair, $d, $e->getMessage()));
            }
        }

        // Pick the most recent successful stat if any
        if (! empty($results)) {
            ksort($results, SORT_STRING);
            $date = array_key_last($results);
            $stat = $results[$date];
            $this->line(json_encode($stat, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->line(json_encode(['pair' => $pair, 'date' => null, 'pos' => 0, 'neg' => 0, 'neu' => 0, 'score' => 0.0], JSON_PRETTY_PRINT));

        return 0;
    }
}
