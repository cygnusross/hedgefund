<?php

declare(strict_types=1);

use App\Application\ContextBuilder;
use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch decision runner analyzes active markets', function () {
    // Mock the ContextBuilder to return null (no tradeable context)
    $mockContextBuilder = \Mockery::mock(ContextBuilder::class);
    $mockContextBuilder->shouldReceive('build')
        ->andReturn(null);

    $this->app->instance(ContextBuilder::class, $mockContextBuilder);

    // Create test markets with unique epics to avoid constraint violations
    Market::factory()->create([
        'symbol' => 'TEST/USD',
        'epic' => 'CS.D.TESTUSD.TODAY.IP',
        'name' => 'TEST/USD',
        'is_active' => true,
    ]);
    Market::factory()->create([
        'symbol' => 'DEMO/USD',
        'epic' => 'CS.D.DEMOUSD.TODAY.IP',
        'name' => 'DEMO/USD',
        'is_active' => true,
    ]);

    $this->artisan('decision:batch --dry-run')
        ->expectsOutput('🚀 Starting batch decision analysis...')
        ->expectsOutput('📰 Ingesting fresh news data...')
        ->expectsOutput('📊 Analyzing 2 markets...')
        ->expectsOutput('🔍 Analyzing TEST/USD...')
        ->expectsOutput('🔍 Analyzing DEMO/USD...')
        ->assertExitCode(0);
});

test('batch decision runner handles no active markets', function () {
    // No active markets
    Market::factory()->create([
        'symbol' => 'INACTIVE/CAD',
        'epic' => 'CS.D.INACTIVECAD.TODAY.IP',
        'name' => 'INACTIVE/CAD',
        'is_active' => false,
    ]);

    $this->artisan('decision:batch --dry-run')
        ->expectsOutput('📊 Analyzing 0 markets...')
        ->expectsOutput('❌ No tradeable opportunities found.')
        ->assertExitCode(0);
});
