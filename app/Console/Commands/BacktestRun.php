<?php

namespace App\Console\Commands;

use App\Application\ContextBuilder;
use App\Backtest\BacktestLedger;
use App\Backtest\BacktestRunner;
use App\Domain\Decision\LiveDecisionEngine;
use App\Domain\Rules\AlphaRules;
use App\Models\Market;
use App\Support\Clock\ClockInterface;
use App\Support\Math\Decimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class BacktestRun extends Command
{
    protected $signature = 'backtest:run {--pair=* : Specific pair(s) to backtest} {--start=} {--end=}';

    protected $description = 'Run the decision engine across stored candles without live API calls.';

    public function handle(): int
    {
        config(['backtest.enabled' => true]);

        $pairs = $this->option('pair');
        if (empty($pairs)) {
            $pairs = Market::where('is_active', true)->pluck('symbol')->toArray();
        }

        if (empty($pairs)) {
            $this->error('No markets available for backtest.');

            return self::FAILURE;
        }

        $start = $this->parseDateOption($this->option('start')) ?? new DateTimeImmutable('-30 days', new DateTimeZone('UTC'));
        $end = $this->parseDateOption($this->option('end')) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($end <= $start) {
            $this->error('End date must be after start date.');

            return self::FAILURE;
        }

        $ledger = new BacktestLedger;
        $contextBuilder = app(ContextBuilder::class);
        $rules = app(AlphaRules::class);
        $clock = app(ClockInterface::class);
        $engine = new LiveDecisionEngine($rules, $clock, $ledger);

        $runner = new BacktestRunner($contextBuilder, $engine, $ledger);
        $result = $runner->run($pairs, $start, $end);

        $trades = $result['trades'];
        $this->info('Backtest complete.');
        $this->line('Pairs: '.implode(', ', $pairs));
        $this->line('Window: '.$start->format('Y-m-d H:i').' → '.$end->format('Y-m-d H:i'));
        $this->line('Trades: '.count($trades));
        $this->line('Realized PnL %: '.$this->formatPercent($result['pnl_pct']));

        if (! empty($trades)) {
            $rows = [];
            foreach ($trades as $trade) {
                $rows[] = [
                    $trade['pair'],
                    $trade['position']['direction'],
                    $trade['position']['entry_ts']->format('Y-m-d H:i'),
                    $trade['closed_at']->format('Y-m-d H:i'),
                    $trade['outcome'] ?? 'exit',
                    $this->formatPercent($trade['pnl_pct']),
                ];
            }
            $this->table(['Pair', 'Dir', 'Entry', 'Exit', 'Outcome', 'PnL %'], $rows);
        }

        return self::SUCCESS;
    }

    private function parseDateOption(?string $value): ?DateTimeImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $this->warn('Invalid date provided: '.$value);

            return null;
        }
    }

    private function formatPercent(float $value): string
    {
        $decimal = Decimal::of($value)->toScale(2, RoundingMode::HALF_UP);

        return sprintf('%.2f', Decimal::toFloat($decimal, 2));
    }
}
