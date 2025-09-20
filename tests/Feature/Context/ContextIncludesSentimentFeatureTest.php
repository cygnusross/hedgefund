<?php

use App\Application\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes sentiment in market context when provider returns 70/30', function () {
    $testClient = new class([]) extends \App\Services\IG\Client
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function get(string $path, array $query = [], array $headers = []): array
        {
            return ['body' => ['longPercent' => 70.0, 'shortPercent' => 30.0, 'asOf' => '2025-09-13T00:00:00Z']];
        }
    };

    $endpoint = new \App\Services\IG\Endpoints\ClientSentimentEndpoint($testClient);
    $provider = new \App\Services\IG\ClientSentimentProvider($endpoint, \Illuminate\Support\Facades\Cache::store());

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
    $res = $cb->build('EUR/USD', $ts);

    expect(data_get($res, 'market.sentiment.long_pct'))->toBe(70.0);
});
