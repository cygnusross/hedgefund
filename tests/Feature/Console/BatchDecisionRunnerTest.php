<?php

declare(strict_types=1);

use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch decision runner analyzes active markets', function () {
    // Create test markets
    Market::factory()->create(['symbol' => 'EUR/USD', 'is_active' => true]);
    Market::factory()->create(['symbol' => 'GBP/USD', 'is_active' => true]);
    Market::factory()->create(['symbol' => 'USD/CAD', 'is_active' => false]);

    $this->artisan('decision:batch --dry-run')
        ->expectsOutput('🚀 Starting batch decision analysis...')
        ->expectsOutput('📰 Ingesting fresh news data...')
        ->expectsOutput('📊 Analyzing 2 markets...')
        ->expectsOutput('🔍 Analyzing EUR/USD...')
        ->expectsOutput('🔍 Analyzing GBP/USD...')
        ->assertExitCode(0);
});

test('batch decision runner handles no active markets', function () {
    // No active markets
    Market::factory()->create(['symbol' => 'USD/CAD', 'is_active' => false]);

    $this->artisan('decision:batch --dry-run')
        ->expectsOutput('📊 Analyzing 0 markets...')
        ->expectsOutput('❌ No tradeable opportunities found.')
        ->assertExitCode(0);
});
