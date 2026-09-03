import type { AllocationSlice, PerformanceReport, PortfolioAllocation, PortfolioSummary, Position } from '@/types/api'
import { DEMO_NUMBERS, DEMO_USER, isoDaysFromAnchor } from './demo'
import { SPY_BULL_CALL_SPREAD } from './strategies'

export const PORTFOLIO_SUMMARY: PortfolioSummary = {
  equity: DEMO_NUMBERS.balance,
  cash: '77120.45',
  buying_power: DEMO_NUMBERS.buyingPower,
  unrealized_pl: DEMO_NUMBERS.unrealizedPl,
  realized_pl: DEMO_NUMBERS.realizedPl,
  options_exposure: '1042.34',
  starting_equity: DEMO_NUMBERS.startingEquity,
  snapshot_at: isoDaysFromAnchor(0),
}

export const OPEN_POSITIONS: Position[] = [
  {
    id: 'position-spy-bcs',
    user_id: DEMO_USER.id,
    strategy_id: SPY_BULL_CALL_SPREAD.id,
    symbol: 'SPY',
    strategy_type: 'bull_call_spread',
    legs: SPY_BULL_CALL_SPREAD.legs ?? [],
    quantity: 1,
    avg_entry_price: '8.60',
    current_value: '1024.00',
    unrealized_pl: DEMO_NUMBERS.unrealizedPl,
    status: 'open',
    dte: SPY_BULL_CALL_SPREAD.dte,
    greeks: { delta: '0.328', gamma: '0.004', theta: '-0.044', vega: '0.077' },
    opened_at: isoDaysFromAnchor(0),
    closed_at: null,
  },
]

export const CLOSED_POSITIONS: Position[] = [
  {
    id: 'position-aapl-bps',
    user_id: DEMO_USER.id,
    strategy_id: null,
    symbol: 'AAPL',
    strategy_type: 'bear_put_spread',
    legs: [],
    quantity: 1,
    avg_entry_price: '1.65',
    current_value: '0.00',
    unrealized_pl: '0.00',
    status: 'closed',
    dte: 0,
    greeks: null,
    opened_at: isoDaysFromAnchor(-20),
    closed_at: isoDaysFromAnchor(-5),
  },
  {
    id: 'position-qqq-ic',
    user_id: DEMO_USER.id,
    strategy_id: null,
    symbol: 'QQQ',
    strategy_type: 'iron_condor',
    legs: [],
    quantity: 1,
    avg_entry_price: '2.10',
    current_value: '0.00',
    unrealized_pl: '63.35',
    status: 'closed',
    dte: 0,
    greeks: null,
    opened_at: isoDaysFromAnchor(-25),
    closed_at: isoDaysFromAnchor(-10),
  },
]

export const ALL_POSITIONS: Position[] = [...OPEN_POSITIONS, ...CLOSED_POSITIONS]

const byUnderlying: AllocationSlice[] = [
  { key: 'SPY', label: 'SPY', value: '1024.00', pct: 62.4 },
  { key: 'CASH', label: 'Cash & buying power', value: '617.34', pct: 37.6 },
]

const byStrategy: AllocationSlice[] = [
  { key: 'bull_call_spread', label: 'Bull Call Spread', value: '1024.00', pct: 100 },
]

export const PORTFOLIO_ALLOCATION: PortfolioAllocation = {
  by_underlying: byUnderlying,
  by_strategy: byStrategy,
}

export const PERFORMANCE_REPORT: PerformanceReport = {
  id: 'perf-report-1',
  period_start: isoDaysFromAnchor(-30),
  period_end: isoDaysFromAnchor(0),
  total_return: '0.25',
  win_rate: '0.50',
  profit_factor: '1.42',
  max_drawdown: '1.80',
  sharpe_like: '0.86',
  generated_at: isoDaysFromAnchor(0),
  avg_win: '63.35',
  avg_loss: '-44.20',
  trade_count: 3,
  monthly_returns: Array.from({ length: 6 }, (_, i) => ({
    month: new Date(new Date().getFullYear(), new Date().getMonth() - (5 - i), 1).toISOString().slice(0, 7),
    return_pct: [1.8, -0.6, 2.4, 0.9, -1.1, 0.25][i] ?? 0,
  })),
}
