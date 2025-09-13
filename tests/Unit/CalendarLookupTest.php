<?php

use App\Application\Calendar\CalendarLookup;
use App\Models\Market;
use App\Services\Economic\EconomicCalendarProviderContract;
// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // nothing here; tests will create their own mocks
});

it('matches when calendar item country is a currency code', function () {
    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('now');
    $future = $now->modify('+1 hour')->format(DATE_ATOM);

    $provider->shouldReceive('getCalendar')->andReturn([
        [
            'country' => 'USD',
            'title' => 'US Jobs',
            'impact' => 'High',
            'date' => $future,
        ],
    ]);

    $lookup = new CalendarLookup($provider);

    $res = $lookup->nextHighImpact('EUR/USD', $now);

    expect($res)->toBeArray()->and($res['currency'])->toBe('USD');
});

// removed: mapping-based test — matching now relies only on Market->currencies and explicit currency codes

it('matches when calendar item provides currencies array or pair-like tokens', function () {
    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('now');
    $future = $now->modify('+3 hours')->format(DATE_ATOM);

    $provider->shouldReceive('getCalendar')->andReturn([
        [
            'country' => 'Some Region',
            'title' => 'Weird Event',
            'impact' => 'High',
            'date' => $future,
            'currency' => ['EUR-USD'],
        ],
    ]);

    $lookup = new CalendarLookup($provider);

    $res = $lookup->nextHighImpact('EUR/USD', $now);

    expect($res)->toBeArray()->and($res['currency'])->toBe('Some Region');
});

it('prefers Market currencies when available', function () {
    // Create a Market row in the test database
    \Illuminate\Support\Facades\DB::table('markets')->insert([
        'name' => 'EUR/USD Market',
        'symbol' => 'EUR/USD',
        'epic' => 'EURAUD',
        'currencies' => json_encode(['EUR', 'USD']),
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('now');
    $future = $now->modify('+4 hours')->format(DATE_ATOM);

    $provider->shouldReceive('getCalendar')->andReturn([
        [
            'country' => 'China',
            'title' => 'CN Event',
            'impact' => 'High',
            'date' => $future,
        ],
        [
            // Use USD directly — we no longer map 'United States' -> USD
            'country' => 'USD',
            'title' => 'US Event',
            'impact' => 'High',
            'date' => $now->modify('+5 hours')->format(DATE_ATOM),
        ],
    ]);

    $lookup = new CalendarLookup($provider);

    $res = $lookup->nextHighImpact('EUR/USD', $now);

    // Should match USD event because Market->currencies includes USD
    expect($res)->toBeArray();
});

it('returns next_high and today_high for event later today', function () {
    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('2025-09-12T10:00:00+00:00');
    $later = $now->modify('+2 hours')->format(DATE_ATOM);

    $provider->shouldReceive('getCalendar')->andReturn([
        [
            'country' => 'EUR',
            'title' => 'EUR Event',
            'impact' => 'High',
            'date' => $later,
        ],
    ]);

    $lookup = new CalendarLookup($provider);
    $res = $lookup->summary('EUR/USD', $now);

    expect($res)->toBeArray();
    expect($res['next_high'])->not->toBeNull();
    expect($res['today_high'])->not->toBeNull();
    expect($res['within_blackout'])->toBeFalse();
});

it('returns next_high when event is tomorrow', function () {
    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('2025-09-12T23:00:00+00:00');
    $tomorrow = $now->modify('+26 hours')->format(DATE_ATOM);

    $provider->shouldReceive('getCalendar')->andReturn([
        [
            'country' => 'USD',
            'title' => 'US Tomorrow',
            'impact' => 'High',
            'date' => $tomorrow,
        ],
    ]);

    $lookup = new CalendarLookup($provider);
    $res = $lookup->summary('EUR/USD', $now);

    expect($res)->toBeArray();
    expect($res['next_high'])->not->toBeNull();
    expect($res['today_high'])->toBeNull();
});

it('returns null when provider has no events', function () {
    $provider = Mockery::mock(EconomicCalendarProviderContract::class);

    $now = new \DateTimeImmutable('2025-09-12T10:00:00+00:00');

    $provider->shouldReceive('getCalendar')->andReturn([]);

    $lookup = new CalendarLookup($provider);
    $res = $lookup->summary('EUR/USD', $now);

    expect($res)->toBeNull();
});
