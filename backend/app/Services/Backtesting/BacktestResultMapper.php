<?php

namespace App\Services\Backtesting;

use App\Enums\BacktestStatus;
use App\Models\BacktestResult;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use Illuminate\Support\Carbon;

/**
 * Maps the ai-engine's raw BacktestResponse (see ai-engine/app/models/api.py
 * and app/api/routes/backtests.py) into this app's `BacktestResult` /
 * `BacktestTrade` rows, and derives the three chart views the frontend
 * renders (drawdown curve, return distribution, monthly returns) that the
 * ai-engine's result doesn't provide directly -- computed once here and
 * stored, rather than recomputed on every read.
 */
class BacktestResultMapper
{
    /**
     * @param  array{status: string, result?: array<string, mixed>|null}  $response
     */
    public function persist(BacktestRun $backtestRun, array $response): void
    {
        $status = $response['status'] ?? 'failed';
        $result = $response['result'] ?? null;

        if ($status !== 'completed' || $result === null) {
            $backtestRun->update(['status' => BacktestStatus::Failed, 'completed_at' => now()]);

            return;
        }

        $trades = $result['trades'] ?? [];
        $equityCurve = $this->buildEquityCurve($backtestRun, $result['equity_curve'] ?? [], $trades);

        BacktestResult::query()->create([
            'backtest_run_id' => $backtestRun->id,
            'metrics' => [
                'win_rate' => (string) ($result['win_rate'] ?? '0'),
                'profit_factor' => $result['profit_factor'] !== null ? (string) $result['profit_factor'] : '--',
                'total_return' => (string) ($result['total_return_pct'] ?? '0'),
                'max_drawdown' => (string) ($result['max_drawdown_pct'] ?? '0'),
                'trade_count' => (int) ($result['trade_count'] ?? count($trades)),
                'sample_period' => $this->samplePeriodLabel($backtestRun),
            ],
            'equity_curve' => $equityCurve,
            'drawdown_curve' => $this->buildDrawdownCurve($equityCurve),
            'return_distribution' => $this->buildReturnDistribution($trades),
            'monthly_returns' => $this->buildMonthlyReturns($equityCurve),
        ]);

        foreach ($trades as $trade) {
            BacktestTrade::query()->create([
                'backtest_run_id' => $backtestRun->id,
                'symbol' => $trade['symbol'] ?? null,
                'entry_at' => $trade['entry_date'] ?? now(),
                'exit_at' => $trade['exit_date'] ?? $trade['entry_date'] ?? now(),
                // The engine's trade payload has no per-leg breakdown (it's a
                // single-net-premium proxy -- see the engine's own comments),
                // so there's nothing real to store here.
                'legs' => [],
                'pnl' => $trade['pnl'] ?? 0,
                'exit_reason' => $trade['exit_reason'] ?? 'unknown',
            ]);
        }

        $backtestRun->update(['status' => BacktestStatus::Completed, 'completed_at' => now()]);
    }

    /**
     * `equity_curve[0]` is the starting equity; `equity_curve[i]` for i>=1
     * is the equity snapshot immediately after `trades[i-1]` closes (the
     * engine appends both in the same step -- see run_backtest()). Trades
     * are produced per-symbol-then-day, not globally chronological across
     * symbols, so the reconstructed series is sorted by date afterward —
     * otherwise a multi-symbol backtest's chart would jump backward in time
     * every time the engine moves on to the next symbol.
     *
     * @param  list<string>  $rawCurve
     * @param  list<array<string, mixed>>  $trades
     * @return list<array{t: string, equity: string}>
     */
    private function buildEquityCurve(BacktestRun $backtestRun, array $rawCurve, array $trades): array
    {
        if ($rawCurve === []) {
            return [];
        }

        $startDate = $backtestRun->config['date_range']['from'] ?? now()->toDateString();

        $points = [['t' => Carbon::parse($startDate)->toIso8601String(), 'equity' => (string) $rawCurve[0]]];

        for ($i = 1; $i < count($rawCurve); $i++) {
            $trade = $trades[$i - 1] ?? null;
            $date = $trade['exit_date'] ?? $trade['entry_date'] ?? $startDate;
            $points[] = ['t' => Carbon::parse($date)->toIso8601String(), 'equity' => (string) $rawCurve[$i]];
        }

        usort($points, fn ($a, $b) => $a['t'] <=> $b['t']);

        return $points;
    }

    /**
     * @param  list<array{t: string, equity: string}>  $equityCurve
     * @return list<array{t: string, drawdown_pct: float}>
     */
    private function buildDrawdownCurve(array $equityCurve): array
    {
        $peak = null;
        $points = [];

        foreach ($equityCurve as $point) {
            $equity = (float) $point['equity'];
            $peak = $peak === null ? $equity : max($peak, $equity);
            $drawdownPct = $peak > 0 ? ($peak - $equity) / $peak : 0.0;
            $points[] = ['t' => $point['t'], 'drawdown_pct' => round($drawdownPct, 4)];
        }

        return $points;
    }

    /**
     * Buckets each trade's P&L (as a % of the debit/risk implied by its own
     * entry price isn't available here, so this buckets raw dollar P&L into
     * fixed-width ranges) — a coarse but honest distribution, not a
     * fabricated one.
     *
     * @param  list<array<string, mixed>>  $trades
     * @return list<array{bucket: string, count: int}>
     */
    private function buildReturnDistribution(array $trades): array
    {
        $buckets = [
            '< -$500' => 0,
            '-$500 to -$100' => 0,
            '-$100 to $0' => 0,
            '$0 to $100' => 0,
            '$100 to $500' => 0,
            '> $500' => 0,
        ];

        foreach ($trades as $trade) {
            $pnl = (float) ($trade['pnl'] ?? 0);
            $key = match (true) {
                $pnl < -500 => '< -$500',
                $pnl < -100 => '-$500 to -$100',
                $pnl < 0 => '-$100 to $0',
                $pnl < 100 => '$0 to $100',
                $pnl < 500 => '$100 to $500',
                default => '> $500',
            };
            $buckets[$key]++;
        }

        return collect($buckets)->map(fn ($count, $bucket) => ['bucket' => $bucket, 'count' => $count])->values()->all();
    }

    /**
     * @param  list<array{t: string, equity: string}>  $equityCurve
     * @return list<array{month: string, return_pct: float}>
     */
    private function buildMonthlyReturns(array $equityCurve): array
    {
        if ($equityCurve === []) {
            return [];
        }

        $byMonth = [];
        foreach ($equityCurve as $point) {
            $month = Carbon::parse($point['t'])->format('Y-m');
            $byMonth[$month] = (float) $point['equity'];
        }

        $months = array_keys($byMonth);
        $returns = [];
        $previous = null;

        foreach ($months as $month) {
            $equity = $byMonth[$month];
            $returns[] = [
                'month' => $month,
                'return_pct' => $previous !== null && $previous > 0 ? round(($equity - $previous) / $previous, 4) : 0.0,
            ];
            $previous = $equity;
        }

        return $returns;
    }

    private function samplePeriodLabel(BacktestRun $backtestRun): string
    {
        $from = $backtestRun->config['date_range']['from'] ?? null;
        $to = $backtestRun->config['date_range']['to'] ?? null;

        return $from && $to ? "{$from} to {$to}" : '--';
    }
}
