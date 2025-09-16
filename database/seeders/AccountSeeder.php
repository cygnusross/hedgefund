<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Primary Trading Sleeve',
                'type' => 'trading',
                'balance' => 1000.00,
                'initial_balance' => 1000.00,
                'available_balance' => 1000.00,
                'used_margin' => 0.00,
                'currency' => 'GBP',
                'is_active' => true,
                'max_risk_per_trade_pct' => 2.00,
                'max_portfolio_risk_pct' => 20.00,
                'description' => 'Main algorithmic trading account for FX positions',
            ],
            [
                'name' => 'Conservative Sleeve',
                'type' => 'trading',
                'balance' => 500.00,
                'initial_balance' => 500.00,
                'available_balance' => 500.00,
                'used_margin' => 0.00,
                'currency' => 'GBP',
                'is_active' => true,
                'max_risk_per_trade_pct' => 1.00,
                'max_portfolio_risk_pct' => 10.00,
                'description' => 'Lower risk trading sleeve for stable returns',
            ],
            [
                'name' => 'Cash Reserve',
                'type' => 'reserve',
                'balance' => 1500.00,
                'initial_balance' => 1500.00,
                'available_balance' => 1500.00,
                'used_margin' => 0.00,
                'currency' => 'GBP',
                'is_active' => true,
                'max_risk_per_trade_pct' => 0.00,
                'max_portfolio_risk_pct' => 0.00,
                'description' => 'Emergency cash reserve, not for trading',
            ],
        ];

        foreach ($accounts as $accountData) {
            Account::create($accountData);
        }
    }
}
