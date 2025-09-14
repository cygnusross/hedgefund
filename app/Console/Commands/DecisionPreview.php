<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Domain\Decision\DecisionEngine;
use Illuminate\Console\Command;

class DecisionPreview extends Command
{
    protected $signature = 'decision:preview {pair} {--force-news} {--force-calendar} {--force-sentiment} {--strict}';

    protected $description = 'Preview DecisionEngine output for a given pair';

    public function handle(): int
    {
        $pair = $this->argument('pair');
        $forceNews = $this->option('force-news');
        $forceCalendar = $this->option('force-calendar');
        $forceSentiment = $this->option('force-sentiment');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Resolve dependencies via the container so tests may bind substitutes
        $builder = app(ContextBuilder::class);
        $engine = app(DecisionEngine::class);

        // Build context; pass force options via $opts
        $opts = [
            'force_news' => (bool) $forceNews,
            'force_calendar' => (bool) $forceCalendar,
            'force_sentiment' => (bool) $forceSentiment,
        ];
        $ctx = $builder->build($pair, $now, null, false, false, $opts);

        if ($ctx === null) {
            $this->error('could not build context');

            return 1;
        }

        $decision = $engine->decide($ctx, app(\App\Domain\Rules\AlphaRules::class));

        $out = [
            'decision' => $decision,
            'market' => $ctx['market'] ?? null,
            'news' => $ctx['news'] ?? null,
            'features' => $ctx['features'] ?? null,
        ];

        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->line($json);

        $blocked = (bool) ($decision['blocked'] ?? ($decision['action'] === 'hold'));

        if ($this->option('strict') && $blocked) {
            return 2;
        }

        return 0;
    }
}
