<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Domain\Decision\Contracts\LiveDecisionEngineContract;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Execution\DecisionToIgOrderConverter;
use App\Models\Market;
use App\Services\IG\WorkingOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BatchDecisionRunner extends Command
{
    protected $signature = 'decision:batch {--dry-run : Preview decisions without executing} {--account= : Account name to use for position sizing}';

    protected $description = 'Analyze all markets and execute the best trading opportunity';

    public function handle(WorkingOrderService $workingOrderService): int
    {
        $this->info('🚀 Starting batch decision analysis...');

        // Step 1: Get active markets
        $markets = Market::where('is_active', true)->get();
        $this->info("📊 Analyzing {$markets->count()} markets...");

        // Step 2: Generate decisions for all markets
        $decisions = $this->analyzeAllMarkets($markets);

        // Step 3: Rank tradeable decisions
        $tradeableDecisions = $this->rankDecisions($decisions);

        if (empty($tradeableDecisions)) {
            $this->info('❌ No tradeable opportunities found.');

            return 0;
        }

        // Step 4: Execute best trade
        $this->executeBestTrade($tradeableDecisions[0], $workingOrderService);

        return 0;
    }

    private function analyzeAllMarkets($markets): array
    {
        $decisions = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $builder = app(ContextBuilder::class);
        $engine = app(LiveDecisionEngineContract::class);

        foreach ($markets as $market) {
            $this->line("🔍 Analyzing {$market->symbol}...");

            try {
                // Each market gets fresh API calls (TwelveData + IG)
                $ctx = $builder->build(
                    $market->symbol,
                    $now,
                    false, // avoid forcing new candle fetch
                    false, // reuse cached spread estimates where possible
                    [],
                    $this->option('account')
                );

                if ($ctx === null) {
                    $this->warn("⚠️ Skipping {$market->symbol} - could not build context");

                    continue;
                }

                $request = DecisionRequest::fromArray($ctx);
                $decisionDto = $engine->decide($request);
                $decision = $decisionDto->toArray();
            } catch (\Exception $e) {
                $this->error("❌ Error analyzing {$market->symbol}: {$e->getMessage()}");
                Log::error("Market analysis failed for {$market->symbol}", [
                    'market' => $market->symbol,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                continue;
            }

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

    private function executeBestTrade(array $bestTrade, WorkingOrderService $workingOrderService): void
    {
        $decision = $bestTrade['decision'];
        $market = $bestTrade['market'];

        // Generate IG order first to show actual values that will be sent
        try {
            $igOrderData = DecisionToIgOrderConverter::convert($decision, $market);

            $this->info("🎯 BEST TRADE: {$market}");
            $this->line("Action: {$decision['action']}");
            $this->line("Confidence: {$decision['confidence']}");
            $this->line("Size: {$decision['size']}");
            $this->line("Entry: {$igOrderData['level']} (raw format for IG)");
            $this->line("Stop Distance: {$igOrderData['stopDistance']} points from entry");
            $this->line("Limit Distance: {$igOrderData['limitDistance']} points from entry");

            if ($decision['reasons'] ?? null) {
                $this->line('Reasons: '.implode(', ', $decision['reasons']));
            }

            $this->line('IG Order Details:');
            $this->line("  Direction: {$igOrderData['direction']}");
            $this->line("  Epic: {$igOrderData['epic']}");
            $this->line("  Entry Level: {$igOrderData['level']} (raw)");
            $this->line("  Stop Distance: {$igOrderData['stopDistance']} points");
            $this->line("  Limit Distance: {$igOrderData['limitDistance']} points");

            if ($this->option('dry-run')) {
                $this->info('🔍 DRY RUN - No trade executed');
            } else {
                $this->info('🚀 Executing trade via IG API...');

                // Execute the trade
                $order = $workingOrderService->createWorkingOrderFromDecision($decision, $market);

                if ($order) {
                    $this->info('✅ Order placed successfully!');
                    $this->line("Deal Reference: {$order->deal_reference}");
                    $this->line("Status: {$order->status}");
                } else {
                    $this->error('❌ Failed to place order - no order returned');
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to execute trade: {$e->getMessage()}");

            // Log the full error for debugging
            Log::error('Batch decision execution failed', [
                'market' => $market,
                'decision' => $decision,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
