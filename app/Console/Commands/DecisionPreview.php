<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Domain\Decision\Contracts\LiveDecisionEngineContract;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Execution\DecisionToIgOrderConverter;
use Illuminate\Console\Command;

class DecisionPreview extends Command
{
    protected $signature = 'decision:preview {pair} {--force-sentiment} {--strict} {--account= : Account name to use for position sizing}';

    protected $description = 'Preview LiveDecisionEngine output for a given pair';

    public function handle(): int
    {
        $pair = $this->argument('pair');
        $forceSentiment = $this->option('force-sentiment');
        $accountName = $this->option('account');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Resolve dependencies via the container so tests may bind substitutes
        $builder = app(ContextBuilder::class);
        $engine = app(LiveDecisionEngineContract::class);

        // Build context; pass force options via $opts
        $opts = ['force_sentiment' => (bool) $forceSentiment];
        $ctx = $builder->build($pair, $now, false, false, $opts, $accountName);

        if ($ctx === null) {
            $this->error('could not build context');

            return 1;
        }

        $decisionDto = $engine->decide(DecisionRequest::fromArray($ctx));
        $decision = $decisionDto->toArray();

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
            'features' => $ctx['features'] ?? null,
            'meta' => $ctx['meta'] ?? null, // Add meta section for debugging
        ];

        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->line($json);

        $action = $decision['action'] ?? 'hold';
        $blocked = (bool) ($decision['blocked'] ?? false);

        if ($this->option('strict') && ($blocked || $action === 'hold')) {
            return 2;
        }

        return 0;
    }
}
