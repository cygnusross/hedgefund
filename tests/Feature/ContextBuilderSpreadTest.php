<?php

use App\Application\Calendar\CalendarLookup;
use App\Application\ContextBuilder;
use App\Domain\FX\SpreadEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Use fully-qualified global DateTime classes to avoid non-compound use statement warnings

uses(RefreshDatabase::class);

it('includes spread_estimate_pips when SpreadEstimator returns a value', function () {
    $now = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));

    $bars5 = [];
    for ($i = 0; $i < 100; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(5 * (100 - $i)).'M'));
        $bars5[] = new \App\Domain\Market\Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $bars30 = [];
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(30 * (50 - $i)).'M'));
        $bars30[] = new \App\Domain\Market\Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $updater = new class($bars5, $bars30) implements \App\Application\Candles\CandleUpdaterContract
    {
        public function __construct(public $b5, public $b30) {}

        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return $interval === '5min' ? $this->b5 : $this->b30;
        }
    };

    $newsProvider = new class implements \App\Services\News\NewsProvider
    {
        public function fetchStat(string $pair, string $date = 'today', bool $fresh = false): array
        {
            return ['pair' => str_replace('/', '-', strtoupper($pair)), 'date' => $date, 'pos' => 0, 'neg' => 0, 'neu' => 0, 'score' => 0.0];
        }
    };

    $calendarProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            return [];
        }

        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    $mockEstimator = Mockery::mock(SpreadEstimator::class);
    $mockEstimator->shouldReceive('estimatePipsForPair')->andReturn(0.9);
    $mockEstimator->shouldReceive('getMarketStatusForPair')->andReturn('OPEN');

    $builder = new ContextBuilder($updater, new CalendarLookup($calendarProvider), $mockEstimator);

    $res = $builder->build('EUR/USD', $now);

    expect($res)->not->toBeNull();
    expect($res)->toHaveKey('market');
    expect($res['market'])->toHaveKey('spread_estimate_pips');
    expect($res['market']['spread_estimate_pips'])->toBe(0.9);
    expect($res['market'])->toHaveKey('status');
    expect($res['market']['status'])->toBe('OPEN');
});
