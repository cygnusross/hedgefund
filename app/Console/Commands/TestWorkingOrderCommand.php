<?php

namespace App\Console\Commands;

use App\Services\IG\WorkingOrderService;
use Illuminate\Console\Command;

class TestWorkingOrderCommand extends Command
{
    protected $signature = 'orders:test-working-order {--dry-run : Show what would be sent without actually creating the order}';

    protected $description = 'Test working order creation with sample data';

    public function handle(WorkingOrderService $workingOrderService): int
    {
        $this->info('Testing working order creation...');

        // Sample decision data (from decision engine output format)
        // Using correct EUR/USD price format based on IG market data (bid: 1.05330, offer: 1.05340)
        $sampleDecision = [
            'action' => 'BUY',
            'confidence' => 0.6,
            'size' => 0.5,
            'entry' => 1.05320, // Below current bid for limit order
            'sl' => 1.05270,    // 5 pips below entry
            'tp' => 1.05370,    // 5 pips above entry
            'reasons' => ['macd_cross', 'rsi_oversold'],
        ];

        $pair = 'EUR/USD';

        try {
            if ($this->option('dry-run')) {
                // Just show what would be converted
                $this->info('DRY RUN - Showing decision data without API call');
                $this->table(
                    ['Field', 'Value'],
                    collect($sampleDecision)->map(fn ($value, $key) => [$key, is_array($value) ? implode(', ', $value) : $value])->values()->toArray()
                );

                return Command::SUCCESS;
            }

            $this->info('Creating working order from decision...');
            $order = $workingOrderService->createWorkingOrderFromDecision($sampleDecision, $pair);

            if ($order) {
                $this->info('Working order created successfully!');
                $this->table(['Field', 'Value'], [
                    ['Deal Reference', $order->deal_reference],
                    ['Epic', $order->epic],
                    ['Direction', $order->direction],
                    ['Size', $order->size],
                    ['Level', $order->level],
                    ['Stop Level', $order->stop_level ?? 'N/A'],
                    ['Limit Level', $order->limit_level ?? 'N/A'],
                    ['Status', $order->status],
                ]);

                return Command::SUCCESS;
            } else {
                $this->error('Failed to create working order');

                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Failed to create working order: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
