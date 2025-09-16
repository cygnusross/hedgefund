<?php

declare(strict_types=1);

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test accounts
    Account::create([
        'name' => 'Test Trading Account',
        'type' => 'trading',
        'balance' => 20000.00,
        'initial_balance' => 20000.00,
        'available_balance' => 20000.00,
        'used_margin' => 0.00,
        'currency' => 'GBP',
        'is_active' => true,
        'max_risk_per_trade_pct' => 2.00,
        'max_portfolio_risk_pct' => 20.00,
        'description' => 'Test account for unit tests',
    ]);

    Account::create([
        'name' => 'Conservative Test Account',
        'type' => 'trading',
        'balance' => 5000.00,
        'initial_balance' => 5000.00,
        'available_balance' => 5000.00,
        'used_margin' => 0.00,
        'currency' => 'GBP',
        'is_active' => true,
        'max_risk_per_trade_pct' => 1.00,
        'max_portfolio_risk_pct' => 10.00,
        'description' => 'Small conservative test account',
    ]);
});

test('account model calculates available balance correctly', function () {
    $account = Account::where('name', 'Test Trading Account')->first();

    expect($account->calculateAvailableBalance())->toBe(20000.0);

    // Reserve some margin
    $account->reserveMargin(5000.0);

    expect($account->calculateAvailableBalance())->toBe(15000.0);
    expect((float) $account->used_margin)->toBe(5000.0);

    // Release margin
    $account->releaseMargin(3000.0);

    expect($account->calculateAvailableBalance())->toBe(18000.0);
    expect((float) $account->used_margin)->toBe(2000.0);
});

test('account model has correct scopes', function () {
    // Test active scope
    $activeAccounts = Account::active()->get();
    expect($activeAccounts)->toHaveCount(2);

    // Test trading scope
    $tradingAccounts = Account::trading()->get();
    expect($tradingAccounts)->toHaveCount(2);

    // Deactivate one account
    $account = Account::first();
    $account->is_active = false;
    $account->save();

    $activeAccounts = Account::active()->get();
    expect($activeAccounts)->toHaveCount(1);
});

test('account model can check available balance for trades', function () {
    $account = Account::where('name', 'Conservative Test Account')->first();

    expect($account->hasAvailableBalance(3000.0))->toBeTrue();
    expect($account->hasAvailableBalance(6000.0))->toBeFalse();

    // Reserve some margin
    $success = $account->reserveMargin(3000.0);
    expect($success)->toBeTrue();
    expect($account->hasAvailableBalance(2000.0))->toBeTrue();
    expect($account->hasAvailableBalance(2500.0))->toBeFalse();

    // Try to reserve more than available
    $success = $account->reserveMargin(3000.0);
    expect($success)->toBeFalse();
});
