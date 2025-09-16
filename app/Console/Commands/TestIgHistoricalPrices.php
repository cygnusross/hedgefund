<?php

namespace App\Console\Commands;

use App\Services\IG\DTO\HistoricalPricesResponse;
use App\Services\IG\Endpoints\HistoricalPricesEndpoint;
use App\Services\IG\Enums\Resolution;
use Illuminate\Console\Command;

class TestIgHistoricalPrices extends Command
{
    protected $signature = 'ig:test-prices {epic=CS.D.EURUSD.MINI.IP} {resolution=MINUTE_5} {points=10}';

    protected $description = 'Test IG historical prices endpoint with various parameters';

    public function handle(HistoricalPricesEndpoint $endpoint): int
    {
        $epic = $this->argument('epic');
        $resolutionString = $this->argument('resolution');
        $numPoints = (int) $this->argument('points');

        $this->info('🔍 Testing IG Historical Prices Endpoint');
        $this->line("Epic: {$epic}");
        $this->line("Resolution: {$resolutionString}");
        $this->line("Points: {$numPoints}");
        $this->line('');

        try {
            // Convert string to Resolution enum
            $resolution = Resolution::from($resolutionString);

            $this->info('📡 Calling IG API...');

            // Call the endpoint
            $response = $endpoint->get($epic, $resolution, $numPoints);

            $this->info('✅ API call successful!');
            $this->line('');

            // Parse response into DTO
            $historicalPricesResponse = HistoricalPricesResponse::fromArray($response);

            // Display allowance info
            $this->info('📊 API Allowance:');
            $this->line("  Remaining: {$historicalPricesResponse->allowance->remainingAllowance}");
            $this->line("  Total: {$historicalPricesResponse->allowance->totalAllowance}");
            $this->line("  Expires in: {$historicalPricesResponse->allowance->allowanceExpiry} seconds");
            $this->line('');

            // Display instrument info
            $this->info('🎯 Instrument:');
            $this->line("  Type: {$historicalPricesResponse->instrumentType}");
            $this->line('  Price points: '.count($historicalPricesResponse->prices));
            $this->line('');

            // Display first few price points
            $this->info('💰 Sample Price Data:');
            foreach (array_slice($historicalPricesResponse->prices, 0, 3) as $i => $price) {
                $this->line('  Point '.($i + 1).':');
                $this->line("    Time: {$price->snapshotTime}");
                $this->line("    Open: {$price->openPrice->bid} / {$price->openPrice->ask}");
                $this->line("    Close: {$price->closePrice->bid} / {$price->closePrice->ask}");
                $this->line("    High: {$price->highPrice->bid} / {$price->highPrice->ask}");
                $this->line("    Low: {$price->lowPrice->bid} / {$price->lowPrice->ask}");
                if ($price->lastTradedVolume) {
                    $this->line("    Volume: {$price->lastTradedVolume}");
                }
                $this->line('');
            }

            // Test Bar conversion
            $this->info('🔄 Testing Bar Conversion:');
            $bars = $historicalPricesResponse->toBars();
            $firstBar = $bars[0] ?? null;

            if ($firstBar) {
                $this->line('  First Bar:');
                $this->line("    Timestamp: {$firstBar->ts->format('Y-m-d H:i:s')} UTC");
                $this->line("    OHLC: {$firstBar->open}, {$firstBar->high}, {$firstBar->low}, {$firstBar->close}");
                $this->line('    Volume: '.($firstBar->volume ?? 'null'));
            }

            $this->line('');
            $this->info('🎉 Test completed successfully!');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Test failed: '.$e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return 1;
        }
    }
}
