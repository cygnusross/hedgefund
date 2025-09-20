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
            // ['name' => 'FTSE 100', 'symbol' => 'XUKX', 'epic' => 'IX.D.FTSE.DAILY.IP'],
            // ['name' => 'Oil - US Crude', 'symbol' => 'WTI/USD', 'epic' => 'CC.D.CL.USS.IP'],
            // ['name' => 'Spot Gold', 'symbol' => 'XAU/USD', 'epic' => 'CS.D.USCGC.TODAY.IP'],
            // ['name' => 'US 500', 'symbol' => 'SPY', 'epic' => 'IX.D.SPTRD.DAILY.IP'],
            [
                'name' => 'EUR/USD',
                'symbol' => 'EUR/USD',
                'epic' => 'CS.D.EURUSD.TODAY.IP',
                'currencies' => '[EUR,USD]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 2.0,
                'adx_min_override' => 22,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'GBP/USD',
                'symbol' => 'GBP/USD',
                'epic' => 'CS.D.GBPUSD.TODAY.IP',
                'currencies' => '[GBP,USD]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 2.2,
                'adx_min_override' => 23,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'EUR/GBP',
                'symbol' => 'EUR/GBP',
                'epic' => 'CS.D.EURGBP.TODAY.IP',
                'currencies' => '[EUR,GBP]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 1.7,
                'adx_min_override' => 20,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'USD/JPY',
                'symbol' => 'USD/JPY',
                'epic' => 'CS.D.USDJPY.TODAY.IP',
                'currencies' => '[USD,JPY]',
                'price_scale' => 100,
                'atr_min_pips_override' => 2.2,
                'adx_min_override' => 20,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'NZD/USD',
                'symbol' => 'NZD/USD',
                'epic' => 'CS.D.NZDUSD.TODAY.IP',
                'currencies' => '[NZD,USD]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 1.6,
                'adx_min_override' => 18,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'AUD/USD',
                'symbol' => 'AUD/USD',
                'epic' => 'CS.D.AUDUSD.TODAY.IP',
                'currencies' => '[AUD,USD]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 1.8,
                'adx_min_override' => 20,
                'z_abs_max_override' => 0.9,
            ],
            [
                'name' => 'USD/CHF',
                'symbol' => 'USD/CHF',
                'epic' => 'CS.D.USDCHF.TODAY.IP',
                'currencies' => '[USD,CHF]',
                'price_scale' => 10000,
                'atr_min_pips_override' => 1.8,
                'adx_min_override' => 21,
                'z_abs_max_override' => 0.9,
            ],
        ];

        foreach ($markets as $market) {
            Market::firstOrCreate(
                ['name' => $market['name']],
                $market
            );
        }
    }
}
