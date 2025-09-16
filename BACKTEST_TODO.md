# Backtesting MVP Implementation TODO / Developer Task List

This file is a **Copilot-friendly backlog** for implementing the backtest engine. Each task is structured with `- [ ]` checkboxes, file paths, and code expectations so that AI coding assistants can generate implementations.

---

## 📋 Setup Contracts

-   [ ] Create `app/Contracts/CandleProviderContract.php`

```php
namespace App\Contracts;

interface CandleProviderContract {
    public function getCandles(string $symbol, \DateTime $from, \DateTime $to, string $interval): array;
}

	•	Create app/Contracts/SpreadProviderContract.php

namespace App\Contracts;

interface SpreadProviderContract {
    public function getSpread(string $symbol, \DateTime $at): float;
}

	•	Create app/Contracts/SentimentProviderContract.php

namespace App\Contracts;

interface SentimentProviderContract {
    public function getSentiment(string $symbol, \DateTime $at): float;
}


⸻

🏗 Implement Historical Providers
	•	Create app/Infrastructure/Backtest/HistoricalCandleRepository.php

namespace App\Infrastructure\Backtest;

use App\Contracts\CandleProviderContract;
use App\Models\Candle;

class HistoricalCandleRepository implements CandleProviderContract {
    public function getCandles(string $symbol, \DateTime $from, \DateTime $to, string $interval): array {
        return Candle::where('symbol', $symbol)
            ->whereBetween('timestamp', [$from, $to])
            ->where('interval', $interval)
            ->orderBy('timestamp')
            ->get()
            ->toArray();
    }
}

	•	Create app/Infrastructure/Backtest/HistoricalSpreadProvider.php

namespace App\Infrastructure\Backtest;

use App\Contracts\SpreadProviderContract;
use App\Models\Spread;

class HistoricalSpreadProvider implements SpreadProviderContract {
    public function getSpread(string $symbol, \DateTime $at): float {
        return Spread::where('symbol', $symbol)
            ->where('timestamp', '<=', $at)
            ->orderByDesc('timestamp')
            ->value('spread') ?? 0.0;
    }
}

	•	Create app/Infrastructure/Backtest/HistoricalSentimentProvider.php

namespace App\Infrastructure\Backtest;

use App\Contracts\SentimentProviderContract;
use App\Models\NewsStat;

class HistoricalSentimentProvider implements SentimentProviderContract {
    public function getSentiment(string $symbol, \DateTime $at): float {
        return NewsStat::where('symbol', $symbol)
            ->where('timestamp', '<=', $at)
            ->orderByDesc('timestamp')
            ->value('sentiment') ?? 0.0;
    }
}


⸻

🔄 ContextBuilder Refactor
	•	Update app/Application/ContextBuilder.php to accept providers via constructor.

public function __construct(
    private readonly CandleProviderContract $candleProvider,
    private readonly SpreadProviderContract $spreadProvider,
    private readonly SentimentProviderContract $sentimentProvider
) {}


⸻

🧪 Backtest Engine
	•	Create app/Backtest/SimulationRunner.php

namespace App\Backtest;

use App\Application\ContextBuilder;

class SimulationRunner {
    public function __construct(private readonly ContextBuilder $contextBuilder) {}

    public function run(string $symbol, \DateTime $from, \DateTime $to): array {
        $results = [];
        $period = new \DatePeriod($from, new \DateInterval('P1D'), $to);

        foreach ($period as $day) {
            // Load from cache or DB
            $context = $this->contextBuilder->build($symbol, $day);

            // Run DecisionEngine as if live trading that day
            $dayResults = $this->simulateDay($context);
            $results[$day->format('Y-m-d')] = $dayResults;
        }

        return $results;
    }

    private function simulateDay($context): array {
        // TODO: integrate with DecisionEngine & TradeReplayer
        return [
            'pnl' => 0.0,
            'trades' => [],
        ];
    }
}

	•	Create app/Backtest/TradeReplayer.php

namespace App\Backtest;

class TradeReplayer {
    public function replay(array $candles, array $orders): array {
        // TODO: execute trades against candle history
        return [];
    }
}

	•	Create app/Backtest/ResultAggregator.php

namespace App\Backtest;

class ResultAggregator {
    public function aggregate(array $dailyResults): array {
        // TODO: compile P&L, risk, and trade stats
        return [];
    }
}


⸻

⚡ Caching Strategy
	•	Preload candles, spreads, events, and news into memory before running backtest.

$candles = Candle::where('symbol', $symbol)
    ->whereBetween('timestamp', [$from, $to])
    ->orderBy('timestamp')
    ->get()
    ->groupBy(fn($c) => $c->timestamp->toDateString());

	•	Use cache (Laravel Cache or arrays) for day-by-day replay instead of repeated DB queries.

⸻

✅ End-to-End Test
	•	Write a feature test that runs SimulationRunner for 1 month of data and asserts non-empty results.

$runner = new SimulationRunner($contextBuilder);
$results = $runner->run("EURUSD", new \DateTime("2022-01-01"), new \DateTime("2022-02-01"));
$this->assertNotEmpty($results);

```
