<?php

declare(strict_types=1);

use App\Application\Candles\IncrementalCandleUpdater;
use App\Domain\Market\Bar;
use App\Services\Prices\PriceProvider;

it('bootstrap_fetches_and_caches', function () {
    $symbol = 'EURUSD';
    $interval = '5min';

    // Create 5 bars t1..t5
    $base = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
    $bars = [];
    for ($i = 0; $i < 5; $i++) {
        $ts = $base->add(new DateInterval('PT'.($i * 5).'M'));
        $bars[] = new Bar($ts, 1.0 + $i, 1.1 + $i, 0.9 + $i, 1.05 + $i, 100 + $i);
    }

    $provider = new class($bars) implements PriceProvider
    {
        public function __construct(public array $bars) {}

        public function getCandles(string $symbol, array $params = []): array
        {
            return $this->bars;
        }
    };

    $cache = new class implements \App\Infrastructure\Prices\CandleCacheContract
    {
        public $store = [];

        public function get(string $symbol, string $interval): ?array
        {
            return $this->store[$symbol.'|'.$interval] ?? null;
        }

        public function put(string $symbol, string $interval, array $bars, int $ttl = 0): void
        {
            $this->store[$symbol.'|'.$interval] = $bars;
        }

        public function tailTs(string $symbol, string $interval): ?DateTimeImmutable
        {
            $k = $symbol.'|'.$interval;
            $v = $this->store[$k] ?? null;
            if (empty($v)) {
                return null;
            }

            return end($v)->ts;
        }
    };

    $updater = new IncrementalCandleUpdater($provider, $cache);

    $res = $updater->sync($symbol, $interval, 500);

    expect($res)->toHaveCount(5);
    // cache populated
    $tail = $cache->tailTs($symbol, $interval);
    expect($tail)->not->toBeNull();
    expect($tail->format('c'))->toEqual($bars[4]->ts->format('c'));
});

it('incremental_merge_with_overlap', function () {
    $symbol = 'EURUSD';
    $interval = '5min';

    $base = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
    $seed = [];
    for ($i = 0; $i < 5; $i++) {
        $ts = $base->add(new DateInterval('PT'.($i * 5).'M'));
        $seed[] = new Bar($ts, 1.0 + $i, 1.1 + $i, 0.9 + $i, 1.05 + $i, 100 + $i);
    }

    // Fresh returns t4..t8 (indexes 3..7)
    $fresh = [];
    for ($i = 3; $i < 8; $i++) {
        $ts = $base->add(new DateInterval('PT'.($i * 5).'M'));
        $fresh[] = new Bar($ts, 2.0 + $i, 2.1 + $i, 1.9 + $i, 2.05 + $i, 200 + $i);
    }

    $provider = new class($fresh) implements PriceProvider
    {
        public function __construct(public array $bars) {}

        public function getCandles(string $symbol, array $params = []): array
        {
            return $this->bars;
        }
    };

    $cache = new class($seed) implements \App\Infrastructure\Prices\CandleCacheContract
    {
        public $store = [];

        public function __construct(public array $seed)
        {
            $this->store['EURUSD|5min'] = $seed;
        }

        public function get(string $symbol, string $interval): ?array
        {
            return $this->store[$symbol.'|'.$interval] ?? null;
        }

        public function put(string $symbol, string $interval, array $bars, int $ttl = 0): void
        {
            $this->store[$symbol.'|'.$interval] = $bars;
        }

        public function tailTs(string $symbol, string $interval): ?DateTimeImmutable
        {
            $k = $symbol.'|'.$interval;
            $v = $this->store[$k] ?? null;
            if (empty($v)) {
                return null;
            }

            return end($v)->ts;
        }
    };

    $updater = new IncrementalCandleUpdater($provider, $cache);

    $merged = $updater->sync($symbol, $interval, 500, 2, 200);

    // Expect t1..t8 (8 bars) with no duplicates
    expect($merged)->toHaveCount(8);
    // Ensure timestamps strictly increasing
    $prev = null;
    foreach ($merged as $b) {
        if ($prev) {
            expect($b->ts > $prev)->toBeTrue();
        }
        $prev = $b->ts;
    }
    // cache updated tail is t8
    $tail = $cache->tailTs($symbol, $interval);
    expect($tail)->not->toBeNull();
    expect($tail->format('c'))->toEqual($merged[array_key_last($merged)]->ts->format('c'));
});

it('idempotent_when_no_new_bars', function () {
    $symbol = 'EURUSD';
    $interval = '5min';

    $base = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
    $seed = [];
    for ($i = 0; $i < 8; $i++) {
        $ts = $base->add(new DateInterval('PT'.($i * 5).'M'));
        $seed[] = new Bar($ts, 1.0 + $i, 1.1 + $i, 0.9 + $i, 1.05 + $i, 100 + $i);
    }

    $provider = new class($seed) implements PriceProvider
    {
        public function __construct(public array $bars) {}

        public function getCandles(string $symbol, array $params = []): array
        {
            return $this->bars;
        }
    };

    $cache = new class($seed) implements \App\Infrastructure\Prices\CandleCacheContract
    {
        public $store = [];

        public function __construct(public array $seed)
        {
            $this->store['EURUSD|5min'] = $seed;
        }

        public function get(string $symbol, string $interval): ?array
        {
            return $this->store[$symbol.'|'.$interval] ?? null;
        }

        public function put(string $symbol, string $interval, array $bars, int $ttl = 0): void
        {
            $this->store[$symbol.'|'.$interval] = $bars;
        }

        public function tailTs(string $symbol, string $interval): ?DateTimeImmutable
        {
            $k = $symbol.'|'.$interval;
            $v = $this->store[$k] ?? null;
            if (empty($v)) {
                return null;
            }

            return end($v)->ts;
        }
    };

    $updater = new IncrementalCandleUpdater($provider, $cache);

    $first = $updater->sync($symbol, $interval, 500);
    $second = $updater->sync($symbol, $interval, 500);

    expect($first)->toEqual($second);
});

it('empty_tail_keeps_cache', function () {
    $symbol = 'EURUSD';
    $interval = '5min';

    $base = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
    $seed = [];
    for ($i = 0; $i < 8; $i++) {
        $ts = $base->add(new DateInterval('PT'.($i * 5).'M'));
        $seed[] = new Bar($ts, 1.0 + $i, 1.1 + $i, 0.9 + $i, 1.05 + $i, 100 + $i);
    }

    $provider = new class implements PriceProvider
    {
        public function getCandles(string $symbol, array $params = []): array
        {
            return [];
        }
    };

    $cache = new class($seed) implements \App\Infrastructure\Prices\CandleCacheContract
    {
        public $store = [];

        public function __construct(public array $seed)
        {
            $this->store['EURUSD|5min'] = $seed;
        }

        public function get(string $symbol, string $interval): ?array
        {
            return $this->store[$symbol.'|'.$interval] ?? null;
        }

        public function put(string $symbol, string $interval, array $bars, int $ttl = 0): void
        {
            $this->store[$symbol.'|'.$interval] = $bars;
        }

        public function tailTs(string $symbol, string $interval): ?DateTimeImmutable
        {
            $k = $symbol.'|'.$interval;
            $v = $this->store[$k] ?? null;
            if (empty($v)) {
                return null;
            }

            return end($v)->ts;
        }
    };

    $updater = new IncrementalCandleUpdater($provider, $cache);
    $res = $updater->sync($symbol, $interval, 500);

    expect($res)->toHaveCount(8);
});
