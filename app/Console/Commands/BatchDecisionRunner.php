<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Domain\Decision\DecisionEngine;
use App\Domain\Execution\DecisionToIgOrderConverter;
use App\Models\Market;
use Illuminate\Console\Command;

class BatchDecisionRunner extends Command
{
    protected $signature = 'decision:batch {--dry-run : Preview decisions without executing} {--account= : Account name to use for position sizing}';

    protected $description = 'Analyze all markets and execute the best trading opportunity';

    public function handle(): int
    {
        $this->info('🚀 Starting batch decision analysis...');

        // Step 1: Fresh news ingestion
        $this->line('📰 Ingesting fresh news data...');
        $this->call('news:ingest');

        // Step 2: Get active markets
        $markets = Market::where('is_active', true)->get();
        $this->info("📊 Analyzing {$markets->count()} markets...");

        // Step 3: Generate decisions for all markets
        $decisions = $this->analyzeAllMarkets($markets);

        // Step 4: Rank tradeable decisions
        $tradeableDecisions = $this->rankDecisions($decisions);

        if (empty($tradeableDecisions)) {
            $this->info('❌ No tradeable opportunities found.');

            return 0;
        }

        // Step 5: Execute best trade
        $this->executeBestTrade($tradeableDecisions[0]);

        return 0;
    }

    private function analyzeAllMarkets($markets): array
    {
        $decisions = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $builder = app(ContextBuilder::class);
        $engine = app(DecisionEngine::class);
        $rules = app(\App\Domain\Rules\AlphaRules::class);

        foreach ($markets as $market) {
            $this->line("🔍 Analyzing {$market->symbol}...");

            // Each market gets fresh API calls (TwelveData + IG)
            $ctx = $builder->build(
                $market->symbol,
                $now,
                null,
                false, // calendar already refreshed by schedule
                false, // sentiment not needed for decisions
                [],
                $this->option('account')
            );

            if ($ctx === null) {
                $this->warn("⚠️ Skipping {$market->symbol} - could not build context");

                continue;
            }

            $decision = $engine->decide($ctx, $rules);

            $decisions[] = [
                'market' => $market->symbol,
                'decision' => $decision,
                'context' => $ctx,
            ];

            // Show decision summary
            $action = $decision['action'] ?? 'hold';
            $confidence = $decision['confidence'] ?? 0;
            $blocked = $decision['blocked'] ?? false;

            if ($action === 'hold' || $blocked) {
                $reasons = implode(', ', $decision['reasons'] ?? []);
                $this->line("  └── HOLD ({$reasons})");
            } else {
                $this->line("  └── {$action} (confidence: {$confidence})");
            }
        }

        return $decisions;
    }

    private function rankDecisions(array $decisions): array
    {
        // Filter tradeable decisions
        $tradeable = array_filter($decisions, function ($item) {
            $decision = $item['decision'];

            return ($decision['action'] ?? 'hold') !== 'hold'
                && ! ($decision['blocked'] ?? false);
        });

        // Sort by confidence descending
        usort($tradeable, function ($a, $b) {
            $confA = $a['decision']['confidence'] ?? 0;
            $confB = $b['decision']['confidence'] ?? 0;

            return $confB <=> $confA;
        });

        $this->info('✅ Found '.count($tradeable).' tradeable opportunities');

        return $tradeable;
    }

    private function executeBestTrade(array $bestTrade): void
    {
        $decision = $bestTrade['decision'];
        $market = $bestTrade['market'];

        $this->info("🎯 BEST TRADE: {$market}");
        $this->line("Action: {$decision['action']}");
        $this->line("Confidence: {$decision['confidence']}");
        $this->line("Size: {$decision['size']}");
        $this->line("Entry: {$decision['entry']}");
        $this->line("Stop: {$decision['sl']}");
        $this->line("Target: {$decision['tp']}");

        if ($decision['reasons'] ?? null) {
            $this->line('Reasons: '.implode(', ', $decision['reasons']));
        }

        // Generate IG order
        try {
            $igOrderData = DecisionToIgOrderConverter::convert($decision, $market);

            $this->line('IG Order:');
            $this->line("  Direction: {$igOrderData['direction']}");
            $this->line("  Size: {$igOrderData['size']}");
            $this->line("  Stop Distance: {$igOrderData['stopDistance']} pips");
            $this->line("  Limit Distance: {$igOrderData['limitDistance']} pips");

            if ($this->option('dry-run')) {
                $this->info('🔍 DRY RUN - No trade executed');
            } else {
                $this->info('🚀 TODO: Execute trade via IG API');
                // TODO: Implement IG order submission
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to generate IG order: {$e->getMessage()}");
        }
    }
}
