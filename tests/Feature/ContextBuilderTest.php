<?php

use App\Application\Calendar\CalendarLookup;
use App\Application\ContextBuilder;
use App\Domain\Market\Bar;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

uses(RefreshDatabase::class);

it('returns null until warm_up', function () {
    $now = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));

    // Fake updater returns too few bars
    $updater = new class implements \App\Application\Candles\CandleUpdaterContract
    {
        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return []; // no data
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

    $builder = new ContextBuilder($updater, new CalendarLookup($calendarProvider));

    $res = $builder->build('EUR/USD', $now);
    expect($res)->toBeNull();
});

it('returns features calendar and blackout true for near event', function () {
    $now = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));

    // Build deterministic bars for 5m and 30m with enough history
    $bars5 = [];
    for ($i = 0; $i < 100; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(5 * (100 - $i)).'M'));
        $bars5[] = new Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $bars30 = [];
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(30 * (50 - $i)).'M'));
        $bars30[] = new Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $updater = new class($bars5, $bars30) implements \App\Application\Candles\CandleUpdaterContract
    {
        public function __construct(public $b5, public $b30) {}

        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return $interval === '5min' ? $this->b5 : $this->b30;
        }
    };

    // Fake calendar returns High impact 45 minutes ahead
    $calendarProvider = new class($now) implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function __construct(public $now) {}

        public function getCalendar(bool $force = false): array
        {
            $when = $this->now->add(new DateInterval('PT45M'));

            return [[
                'title' => 'Important',
                'country' => 'EUR',
                'date_utc' => $when->format(DATE_ATOM),
                'impact' => 'High',
            ]];
        }

        public function ingest(array $items): void
        {
            // no-op for tests
        }
    };

    $builder = new ContextBuilder($updater, new \App\Application\Calendar\CalendarLookup($calendarProvider));

    $res = $builder->build('EUR/USD', $now);

    expect($res)->not->toBeNull();
    expect($res['features'])->toHaveKey('ema20');
    expect($res['features'])->toHaveKey('atr5m');
    expect($res['features'])->toHaveKey('adx5m');
    expect($res['calendar']['next_high']['minutes_to'])->toBe(45);
    expect($res['calendar']['within_blackout'])->toBeTrue();
    // New provenance/meta fields
    expect(array_key_exists('meta', $res))->toBeTrue();
    expect(is_int($res['meta']['data_age_sec']) || is_null($res['meta']['data_age_sec']))->toBeTrue();
    expect($res['meta']['bars_5m']['count'])->toBe(100);
    expect($res['meta']['bars_30m']['count'])->toBe(50);
    expect($res['meta']['calendar_blackout_window_min'])->toBe((int) config('decision.blackout_minutes_high', 60));
    expect($res['meta']['schema_version'])->toBe('1.0.0');
    expect($res['meta']['pair_norm'])->toBe('EUR-USD');
    // calendar should not duplicate the blackout window
    expect(isset($res['calendar']['blackout_minutes_high']))->toBeFalse();
    // Market extras (promoted to top-level)
    expect($res)->toHaveKey('market');
    expect($res['market']['last_price'])->toBe($bars5[count($bars5) - 1]->close);
    expect($res['market']['atr5m_pips'])->toBe(round($res['market']['atr5m_pips'], 1));
    expect($res['market']['next_bar_eta_sec'])->toBeGreaterThanOrEqual(0);
    expect($res['market']['next_bar_eta_sec'])->toBeLessThanOrEqual(300);
});

it('blackout false when event > 60min', function () {
    $now = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));

    $bars5 = [];
    for ($i = 0; $i < 100; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(5 * (100 - $i)).'M'));
        $bars5[] = new Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $bars30 = [];
    for ($i = 0; $i < 50; $i++) {
        $ts = $now->sub(new DateInterval('PT'.(30 * (50 - $i)).'M'));
        $bars30[] = new Bar($ts, 1.0, 1.1, 0.9, 1.05, 100);
    }

    $updater = new class($bars5, $bars30) implements \App\Application\Candles\CandleUpdaterContract
    {
        public function __construct(public $b5, public $b30) {}

        public function sync(string $symbol, string $interval, int $limit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            return $interval === '5min' ? $this->b5 : $this->b30;
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

    $builder = new ContextBuilder($updater, new \App\Application\Calendar\CalendarLookup($calendarProvider));

    $res = $builder->build('EUR/USD', $now);
    expect($res['blackout'])->toBeFalse();
});
