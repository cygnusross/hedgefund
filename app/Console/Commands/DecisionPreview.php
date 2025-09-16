<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Domain\Decision\DecisionEngine;
use App\Domain\Execution\DecisionToIgOrderConverter;
use Illuminate\Console\Command;

class DecisionPreview extends Command
{
    protected $signature = 'decision:preview {pair} {--force-calendar} {--force-sentiment} {--strict} {--account= : Account name to use for position sizing} {--refresh-news : Ingest fresh news before building context}';

    protected $description = 'Preview DecisionEngine output for a given pair';

    public function handle(): int
    {
        $pair = $this->argument('pair');
        $forceCalendar = $this->option('force-calendar');
        $forceSentiment = $this->option('force-sentiment');
        $refreshNews = $this->option('refresh-news');
        $accountName = $this->option('account');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Ingest fresh news before building context if requested
        if ($refreshNews) {
            $this->line('Refreshing news data...');
            $this->call('news:ingest', ['--today' => true]);
        }

        // Resolve dependencies via the container so tests may bind substitutes
        $builder = app(ContextBuilder::class);
        $engine = app(DecisionEngine::class);

        // Build context; pass force options via $opts
        $opts = [
            'force_calendar' => (bool) $forceCalendar,
            'force_sentiment' => (bool) $forceSentiment,
        ];
        $ctx = $builder->build($pair, $now, null, false, false, $opts, $accountName);

        if ($ctx === null) {
            $this->error('could not build context');

            return 1;
        }

        $decision = $engine->decide($ctx, app(\App\Domain\Rules\AlphaRules::class));

        // Generate IG order data if decision is not hold
        $igOrder = null;
        if (($decision['action'] ?? 'hold') !== 'hold' && ! ($decision['blocked'] ?? false)) {
            try {
                $igOrderData = DecisionToIgOrderConverter::convert($decision, $pair);
                $igOrder = [
                    'market' => $pair,
                    'direction' => $igOrderData['direction'],
                    'size' => $igOrderData['size'],
                    'priceLevel' => $igOrderData['level'],
                    'timeInForce' => $igOrderData['timeInForce'],
                    'stop' => $igOrderData['stopDistance'],
                    'limit' => $igOrderData['limitDistance'],
                ];
            } catch (\Exception $e) {
                // If IG order generation fails, continue without it
                $this->error('Failed to generate IG order: '.$e->getMessage());
            }
        }

        $out = [
            'decision' => $decision,
            'ig_order' => $igOrder,
            'market' => $ctx['market'] ?? null,
            'news' => $ctx['news'] ?? null,
            'features' => $ctx['features'] ?? null,
            'meta' => $ctx['meta'] ?? null, // Add meta section for debugging
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
