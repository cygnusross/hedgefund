<?php

namespace Database\Seeders;

use App\Models\Market;
use Illuminate\Database\Seeder;

class MarketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $markets = [
            // ['name' => 'FTSE 100', 'symbol' => 'UKX'],
            // ['name' => 'Oil - US Crude', 'symbol' => 'CL'],
            ['name' => 'EUR/USD', 'symbol' => 'EUR/USD', 'epic' => 'CS.D.EURUSD.MINI.IP', 'currencies' => '[EUR,USD]'],
            ['name' => 'GBP/USD', 'symbol' => 'GBP/USD', 'epic' => 'CS.D.GBPUSD.MINI.IP', 'currencies' => '[GBP,USD]'],
            ['name' => 'EUR/GBP', 'symbol' => 'EUR/GBP', 'epic' => 'CS.D.EURGBP.MINI.IP', 'currencies' => '[EUR,GBP]'],
            ['name' => 'USD/JPY', 'symbol' => 'USD/JPY', 'epic' => 'CS.D.USDJPY.MINI.IP', 'currencies' => '[USD,JPY]'],
            // ['name' => 'Spot Gold', 'symbol' => 'GOLD'],
            // ['name' => 'US 500', 'symbol' => 'SPX'],
        ];

        foreach ($markets as $market) {
            Market::firstOrCreate(
                ['name' => $market['name']],
                $market
            );
        }
    }
}
