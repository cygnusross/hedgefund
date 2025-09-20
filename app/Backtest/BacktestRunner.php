<?php

namespace App\Backtest;

use App\Application\ContextBuilder;
use App\Domain\Decision\DTO\DecisionRequest;
use App\Domain\Decision\LiveDecisionEngine;
use App\Models\Candle;
use Illuminate\Support\Carbon;

class BacktestRunner
{
    public function __construct(
        private ContextBuilder $contextBuilder,
        private LiveDecisionEngine $decisionEngine,
        private BacktestLedger $ledger,
    ) {}

    public function run(array $pairs, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $pairCandles = [];
        $timeline = [];

        foreach ($pairs as $pair) {
            $records = Candle::query()
                ->where('pair', $pair)
                ->where('interval', '5min')
                ->whereBetween('timestamp', [$start, $end])
                ->orderBy('timestamp')
                ->get();

            foreach ($records as $record) {
                $ts = Carbon::parse($record->timestamp, 'UTC')->toDateTimeImmutable();
                $key = $ts->format('Y-m-d H:i:s');

                $pairCandles[$pair][$key] = [
                    'ts' => $ts,
                    'open' => (float) $record->open,
                    'high' => (float) $record->high,
                    'low' => (float) $record->low,
                    'close' => (float) $record->close,
                ];
                $timeline[$key] = $ts;
            }
        }

        ksort($timeline);

        foreach ($timeline as $key => $ts) {
            foreach ($pairs as $pair) {
                $candle = $pairCandles[$pair][$key] ?? null;
                if (! $candle) {
                    continue;
                }

                $this->settleOpenPositions($pair, $candle);

                $context = $this->contextBuilder->build($pair, $ts);
                if ($context === null) {
                    continue;
                }

                $decision = $this->decisionEngine->decide(DecisionRequest::fromArray($context))->toArray();

                if (($decision['blocked'] ?? false) === true) {
                    continue;
                }

                if (! in_array($decision['action'] ?? 'hold', ['buy', 'sell'], true)) {
                    continue;
                }

                $this->openPosition($pair, $decision, $ts);
            }
        }

        $this->flushRemainingPositions($pairCandles);

        return [
            'trades' => $this->ledger->trades(),
            'pnl_pct' => $this->ledger->todaysPnLPct(),
        ];
    }

    private function settleOpenPositions(string $pair, array $candle): void
    {
        $positions = $this->ledger->positionsForPair($pair);
        if (empty($positions)) {
            return;
        }

        foreach ($positions as $id => $position) {
            $outcome = null;

            if ($position['direction'] === 'buy') {
                if ($candle['low'] <= $position['sl']) {
                    $outcome = 'loss';
                } elseif ($candle['high'] >= $position['tp']) {
                    $outcome = 'win';
                }
            } else {
                if ($candle['high'] >= $position['sl']) {
                    $outcome = 'loss';
                } elseif ($candle['low'] <= $position['tp']) {
                    $outcome = 'win';
                }
            }

            if ($outcome !== null) {
                $pnlPct = $this->calculatePnlPct($position, $outcome);
                $this->ledger->closePosition($pair, $id, $outcome, $pnlPct, $candle['ts']);
            }
        }
    }

    private function openPosition(string $pair, array $decision, \DateTimeImmutable $ts): void
    {
        $riskPct = (float) ($decision['risk_pct'] ?? 0.0);
        if ($riskPct <= 0.0) {
            return;
        }

        $position = [
            'id' => sprintf('%s-%s-%s', $pair, $ts->format('YmdHis'), uniqid('', true)),
            'direction' => $decision['action'],
            'entry' => (float) $decision['entry'],
            'sl' => (float) $decision['sl'],
            'tp' => (float) $decision['tp'],
            'risk_pct' => $riskPct,
            'entry_ts' => $ts,
        ];

        $this->ledger->openPosition($pair, $position);
    }

    private function flushRemainingPositions(array $pairCandles): void
    {
        foreach ($this->ledger->openPositions() as $pair => $positions) {
            $candles = $pairCandles[$pair] ?? [];
            if (empty($candles)) {
                continue;
            }

            $last = end($candles);
            foreach ($positions as $id => $position) {
                $pnlPct = $this->calculateExitPnlPct($position, $last['close']);
                $this->ledger->closePosition($pair, $id, 'exit', $pnlPct, $last['ts']);
            }
        }
    }

    private function calculatePnlPct(array $position, string $outcome): float
    {
        $riskValue = $this->riskDistance($position);
        $riskPct = $position['risk_pct'];

        if ($outcome === 'loss') {
            return -$riskPct;
        }

        $rewardValue = $this->rewardDistance($position);
        if ($rewardValue <= 0) {
            return $riskPct;
        }

        return $riskPct * ($rewardValue / $riskValue);
    }

    private function calculateExitPnlPct(array $position, float $exitPrice): float
    {
        $riskValue = $this->riskDistance($position);
        if ($riskValue <= 0) {
            return 0.0;
        }

        $riskPct = $position['risk_pct'];
        $reward = $position['direction'] === 'buy'
            ? $exitPrice - $position['entry']
            : $position['entry'] - $exitPrice;

        return $riskPct * ($reward / $riskValue);
    }

    private function riskDistance(array $position): float
    {
        $distance = $position['direction'] === 'buy'
            ? $position['entry'] - $position['sl']
            : $position['sl'] - $position['entry'];

        return max(abs($distance), 1e-6);
    }

    private function rewardDistance(array $position): float
    {
        $distance = $position['direction'] === 'buy'
            ? $position['tp'] - $position['entry']
            : $position['entry'] - $position['tp'];

        return max($distance, 0.0);
    }
}
