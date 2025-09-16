<?php

namespace App\Console\Commands;

use App\Services\Prices\PriceProvider;
use Illuminate\Console\Command;

class DemoPriceProviders extends Command
{
    protected $signature = 'demo:price-providers';

    protected $description = 'Demonstrate switching between TwelveData and IG price providers';

    public function handle(): int
    {
        $this->info('🚀 Price Provider Demo');
        $this->info('====================');
        $this->newLine();

        // Test current provider
        $this->info('📊 Current Configuration:');
        $currentDriver = config('pricing.driver');
        $this->line("Config driver: {$currentDriver}");

        $provider = app(PriceProvider::class);
        $this->line('Resolved provider: '.get_class($provider));
        $this->newLine();

        // Test TwelveData if it's the current driver
        if ($currentDriver === 'twelvedata') {
            $this->testTwelveData($provider);
        }

        // Test IG provider
        $this->info('📈 Testing IG Provider:');

        // Switch to IG temporarily
        config(['pricing.driver' => 'ig']);
        app()->forgetInstance(PriceProvider::class);

        $igProvider = app(PriceProvider::class);
        $this->line('Resolved provider: '.get_class($igProvider));

        try {
            $bars = $igProvider->getCandles('EUR/USD', [
                'interval' => '5min',
                'outputsize' => 2,
            ]);

            $this->info('✅ IG: Retrieved '.count($bars).' bars');

            if (count($bars) > 0) {
                $latest = $bars[0];
                $this->line("   Latest: {$latest->ts->format('Y-m-d H:i:s T')} Close: {$latest->close}");
            }
        } catch (\Exception $e) {
            $this->error('❌ IG error: '.$e->getMessage());
        }

        $this->newLine();

        // Show configuration mappings
        $this->showMappings();

        // Reset configuration
        config(['pricing.driver' => $currentDriver]);
        app()->forgetInstance(PriceProvider::class);

        $this->newLine();
        $this->info('✨ Demo completed!');

        return self::SUCCESS;
    }

    private function testTwelveData(PriceProvider $provider): void
    {
        $this->info('📊 Testing TwelveData Provider:');

        if (! config('pricing.twelvedata.api_key')) {
            $this->warn('⚠️  TwelveData API key not configured');
            $this->newLine();

            return;
        }

        try {
            $bars = $provider->getCandles('EUR/USD', [
                'interval' => '5min',
                'outputsize' => 2,
            ]);

            $this->info('✅ TwelveData: Retrieved '.count($bars).' bars');

            if (count($bars) > 0) {
                $latest = $bars[0];
                $this->line("   Latest: {$latest->ts->format('Y-m-d H:i:s')} Close: {$latest->close}");
            }
        } catch (\Exception $e) {
            $this->error('❌ TwelveData error: '.$e->getMessage());
        }

        $this->newLine();
    }

    private function showMappings(): void
    {
        $this->info('🗺️  IG Symbol Mapping (sample):');
        $symbolMap = config('pricing.ig.symbol_map', []);

        foreach (array_slice($symbolMap, 0, 3, true) as $symbol => $epic) {
            $this->line("   {$symbol} → {$epic}");
        }

        $this->newLine();

        $this->info('⏰ IG Resolution Mapping (sample):');
        $resolutionMap = config('pricing.ig.resolution_map', []);

        foreach (array_slice($resolutionMap, 0, 4, true) as $interval => $resolution) {
            $this->line("   {$interval} → {$resolution}");
        }
    }
}
