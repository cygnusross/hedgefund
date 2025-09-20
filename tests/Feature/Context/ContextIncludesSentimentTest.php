<?php

use App\Application\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes sentiment in the market context when provider returns data', function () {
    // Build a test client that returns deterministic sentiment for endpoint
    $testClient = new class([]) extends \App\Services\IG\Client
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function get(string $path, array $query = [], array $headers = []): array
        {
            return ['body' => ['longPercent' => 60.0, 'shortPercent' => 40.0, 'asOf' => '2025-09-13T00:00:00Z']];
        }
    };

    $endpoint = new \App\Services\IG\Endpoints\ClientSentimentEndpoint($testClient);
    $provider = new \App\Services\IG\ClientSentimentProvider($endpoint, \Illuminate\Support\Facades\Cache::store());

    // Build a minimal ContextBuilder with mocks: use real CandleUpdaterContract mock via container
    // Create a minimal updater that returns 30 5m bars and 30 30m bars
    $updater = new class implements \App\Application\Candles\CandleUpdaterContract
    {
        public function sync(string $symbol, string $interval, int $bootstrapLimit, int $overlapBars = 2, int $tailFetchLimit = 200): array
        {
            $bars = [];
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $step = $interval === '5min' ? 300 : 1800;
            for ($i = 30; $i >= 1; $i--) {
                $ts = $now->sub(new DateInterval('PT'.($i * $step).'S'));
                $bars[] = new \App\Domain\Market\Bar($ts, 1.0, 1.1, 0.9, 1.05, 100.0);
            }

            return $bars;
        }
    };

    $calendarProvider = new class implements \App\Services\Economic\EconomicCalendarProviderContract
    {
        public function getCalendar(bool $force = false): array
        {
            $future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT1H'));

            return [[
                'title' => 'Test Event',
                'date' => $future->format('Y-m-d\TH:i:sP'),
                'country' => 'EUR',
                'impact' => 'High',
            ]];
        }

        public function ingest(array $items): void {}
    };

    $calendar = new \App\Application\Calendar\CalendarLookup($calendarProvider);

    $cb = new ContextBuilder($updater, $calendar, null, $provider);

    $ts = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // Call build — we won't exercise the full pipeline; ensure it doesn't throw and returns array
    $res = $cb->build('EUR/USD', $ts);

    expect(is_array($res))->toBeTrue();
    expect(isset($res['market']))->toBeTrue();
    expect(isset($res['market']['sentiment']))->toBeTrue();
    expect($res['market']['sentiment']['long_pct'])->toBe(60.0);
    expect($res['market']['sentiment']['short_pct'])->toBe(40.0);
});
