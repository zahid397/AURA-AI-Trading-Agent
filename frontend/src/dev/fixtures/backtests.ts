import type { BacktestConfig, BacktestDetail, BacktestRun, BacktestTrade } from '@/types/api'
import { buildEquityCurve } from './equityCurve'
import { isoDateDaysFromAnchor, isoDaysFromAnchor } from './demo'

const DEFAULT_CONFIG: BacktestConfig = {
  strategy: 'bull_call_spread',
  symbols: ['SPY'],
  date_range: { from: isoDateDaysFromAnchor(-180), to: isoDateDaysFromAnchor(-1) },
  starting_capital: '100000.00',
  dte: { min: 21, max: 45 },
  entry_rules: 'Enter on bullish regime confirmation with IV percentile below 50.',
  exit_rules: 'Exit at 50% of max profit or 7 DTE, whichever first.',
  confidence_threshold: 0.55,
  slippage_pct: 0.02,
  risk_policy_version: 1,
}

export const BACKTEST_RUN: BacktestRun = {
  id: 'backtest-spy-bcs-180d',
  user_id: 'user-demo-001',
  config: DEFAULT_CONFIG,
  seed_hash: 'seed-9f2e1a',
  status: 'completed',
  provenance: 'simulated_fixture',
  started_at: isoDaysFromAnchor(-1),
  completed_at: isoDaysFromAnchor(-1),
  progress_pct: 100,
}

const equity = buildEquityCurve('3M', 108430.2, 100000)
const drawdown = equity.map((p, i) => ({ t: p.t, drawdown_pct: Math.max(0, 3.4 - i * 0.06 + Math.sin(i / 4) * 0.8) }))

export const BACKTEST_TRADES: BacktestTrade[] = [
  { id: 'bt-trade-1', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-170), exit_at: isoDaysFromAnchor(-155), legs: [], pnl: '420.00', exit_reason: 'target_profit' },
  { id: 'bt-trade-2', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-150), exit_at: isoDaysFromAnchor(-138), legs: [], pnl: '-210.00', exit_reason: 'stop_loss' },
  { id: 'bt-trade-3', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-130), exit_at: isoDaysFromAnchor(-112), legs: [], pnl: '615.00', exit_reason: 'target_profit' },
  { id: 'bt-trade-4', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-100), exit_at: isoDaysFromAnchor(-90), legs: [], pnl: '180.00', exit_reason: 'expiration' },
  { id: 'bt-trade-5', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-80), exit_at: isoDaysFromAnchor(-63), legs: [], pnl: '-95.00', exit_reason: 'stop_loss' },
  { id: 'bt-trade-6', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-55), exit_at: isoDaysFromAnchor(-40), legs: [], pnl: '510.00', exit_reason: 'target_profit' },
  { id: 'bt-trade-7', backtest_run_id: BACKTEST_RUN.id, symbol: 'SPY', entry_at: isoDaysFromAnchor(-30), exit_at: isoDaysFromAnchor(-14), legs: [], pnl: '340.00', exit_reason: 'target_profit' },
]

export const BACKTEST_DETAIL: BacktestDetail = {
  ...BACKTEST_RUN,
  result: {
    backtest_run_id: BACKTEST_RUN.id,
    metrics: {
      win_rate: '0.71',
      profit_factor: '2.18',
      total_return: '0.0843',
      max_drawdown: '0.034',
      trade_count: BACKTEST_TRADES.length,
      sample_period: '180 days',
    },
    equity_curve: equity,
    drawdown_curve: drawdown,
    return_distribution: [
      { bucket: '< -5%', count: 0 },
      { bucket: '-5% to -1%', count: 2 },
      { bucket: '-1% to 1%', count: 0 },
      { bucket: '1% to 5%', count: 3 },
      { bucket: '> 5%', count: 2 },
    ],
    monthly_returns: Array.from({ length: 6 }, (_, i) => ({
      month: new Date(new Date().getFullYear(), new Date().getMonth() - (5 - i), 1).toISOString().slice(0, 7),
      return_pct: [2.1, -0.8, 3.2, 1.4, -0.5, 1.8][i] ?? 0,
    })),
  },
  trades: BACKTEST_TRADES,
}

export const BACKTEST_RUNS: BacktestRun[] = [BACKTEST_RUN]
