import type { EvidenceSection, PayoffCurve, PayoffPoint, Strategy, StrategyDetail, StrategyLeg } from '@/types/api'
import { AGENT_RUN_ID } from './agents'
import { isoDateDaysFromAnchor, isoDaysFromAnchor } from './demo'

const DTE = 30
const EXPIRATION = isoDateDaysFromAnchor(DTE)
const QUOTE_TIME = isoDaysFromAnchor(0)

// --- SPY Bull Call Spread (executed) ----------------------------------------

const SPY_LEGS: StrategyLeg[] = [
  {
    id: 'leg-spy-1',
    strategy_id: 'strategy-spy-bcs',
    action: 'buy',
    occ_symbol: `SPY${EXPIRATION.slice(2, 10).replace(/-/g, '')}C00570000`,
    underlying: 'SPY',
    option_type: 'call',
    strike: '570.00',
    expiration: EXPIRATION,
    position_intent: 'buy_to_open',
    ratio_qty: 1,
    quote_bid: '13.20',
    quote_ask: '13.45',
    delta: '0.612',
    gamma: '0.021',
    theta: '-0.142',
    vega: '0.318',
    open_interest: 18420,
    volume: 3120,
    quote_time: QUOTE_TIME,
  },
  {
    id: 'leg-spy-2',
    strategy_id: 'strategy-spy-bcs',
    action: 'sell',
    occ_symbol: `SPY${EXPIRATION.slice(2, 10).replace(/-/g, '')}C00600000`,
    underlying: 'SPY',
    option_type: 'call',
    strike: '600.00',
    expiration: EXPIRATION,
    position_intent: 'sell_to_open',
    ratio_qty: 1,
    quote_bid: '4.65',
    quote_ask: '4.85',
    delta: '0.284',
    gamma: '0.017',
    theta: '-0.098',
    vega: '0.241',
    open_interest: 24110,
    volume: 5410,
    quote_time: QUOTE_TIME,
  },
]

export const SPY_BULL_CALL_SPREAD: Strategy = {
  id: 'strategy-spy-bcs',
  agent_run_id: AGENT_RUN_ID,
  symbol: 'SPY',
  strategy_type: 'bull_call_spread',
  regime: 'bullish',
  thesis:
    'SPY is in a confirmed uptrend with momentum and breadth confluence; a moderate-width bull call spread captures further upside to the 6% OTM strike while capping downside to the net debit paid.',
  confidence: '0.87',
  net_debit: '8.60',
  net_credit: null,
  max_profit: '2140.00',
  max_loss: '860.00',
  breakevens: ['578.60'],
  probability_of_profit: '0.64',
  liquidity_score: '0.91',
  score: '0.87',
  score_breakdown: {
    deterministic: [
      { label: 'Liquidity (OI/volume/spread)', weight: 0.25, value: 0.91 },
      { label: 'Risk/reward ratio', weight: 0.2, value: 0.83 },
      { label: 'Probability of profit', weight: 0.2, value: 0.64 },
      { label: 'Quote freshness', weight: 0.1, value: 0.98 },
    ],
    model_judgment: [
      { label: 'Trend & momentum confluence', weight: 0.15, value: 0.9 },
      { label: 'Macro/sector alignment', weight: 0.1, value: 0.78 },
    ],
    total: 0.87,
  },
  quote_timestamp: QUOTE_TIME,
  dte: DTE,
  rank: 1,
  selected: true,
  legs: SPY_LEGS,
}

const SPY_EVIDENCE: EvidenceSection[] = [
  { key: 'market_conditions', title: 'Market Conditions', body: 'SPY trading above its 20/50-day moving averages with rising volume on up days over the trailing two weeks.', bullets: ['Price above 20DMA and 50DMA', 'Advance/decline line trending higher', 'VIX in the low 14s -- benign volatility regime'] },
  { key: 'technical_signals', title: 'Technical Signals', body: 'Momentum oscillators confirm trend without being extended; no bearish divergence detected.', bullets: ['RSI(14) at 58, no overbought divergence', 'MACD histogram positive and expanding'] },
  { key: 'volatility', title: 'Volatility', body: 'Implied volatility percentile is moderate (38th), keeping the spread debit reasonable relative to width.' },
  { key: 'liquidity', title: 'Liquidity', body: 'Both legs trade on tight markets with deep open interest, minimizing slippage risk on entry and exit.', bullets: ['Long leg OI 18,420 / vol 3,120', 'Short leg OI 24,110 / vol 5,410', 'Combined spread width vs mid: 1.8%'] },
  { key: 'risk_analysis', title: 'Risk Analysis', body: 'Max loss is capped at the net debit ($860 for 1 contract), which is 0.86% of starting equity -- inside policy limits.' },
  { key: 'expected_outcome', title: 'Expected Outcome (est.)', body: 'Model estimates a 64% probability of profit by expiration under current drift and volatility assumptions. This is a statistical estimate from the pricing model, not a guarantee.' },
  { key: 'invalidation', title: 'Invalidation Conditions', body: 'Thesis is invalidated by a close below the 50-day moving average or a broad-market volatility spike (VIX > 22) before expiration.' },
]

// --- NVDA Iron Condor (pending approval) ------------------------------------

const NVDA_LEGS: StrategyLeg[] = [
  { id: 'leg-nvda-1', strategy_id: 'strategy-nvda-ic', action: 'buy', occ_symbol: `NVDA${EXPIRATION.slice(2, 10).replace(/-/g, '')}P00115000`, underlying: 'NVDA', option_type: 'put', strike: '115.00', expiration: EXPIRATION, position_intent: 'buy_to_open', ratio_qty: 1, quote_bid: '1.85', quote_ask: '2.00', delta: '-0.152', gamma: '0.012', theta: '-0.061', vega: '0.145', open_interest: 9210, volume: 1440, quote_time: QUOTE_TIME },
  { id: 'leg-nvda-2', strategy_id: 'strategy-nvda-ic', action: 'sell', occ_symbol: `NVDA${EXPIRATION.slice(2, 10).replace(/-/g, '')}P00120000`, underlying: 'NVDA', option_type: 'put', strike: '120.00', expiration: EXPIRATION, position_intent: 'sell_to_open', ratio_qty: 1, quote_bid: '2.95', quote_ask: '3.10', delta: '-0.231', gamma: '0.015', theta: '-0.078', vega: '0.171', open_interest: 11640, volume: 2210, quote_time: QUOTE_TIME },
  { id: 'leg-nvda-3', strategy_id: 'strategy-nvda-ic', action: 'sell', occ_symbol: `NVDA${EXPIRATION.slice(2, 10).replace(/-/g, '')}C00138000`, underlying: 'NVDA', option_type: 'call', strike: '138.00', expiration: EXPIRATION, position_intent: 'sell_to_open', ratio_qty: 1, quote_bid: '2.55', quote_ask: '2.70', delta: '0.219', gamma: '0.014', theta: '-0.072', vega: '0.163', open_interest: 10380, volume: 1980, quote_time: QUOTE_TIME },
  { id: 'leg-nvda-4', strategy_id: 'strategy-nvda-ic', action: 'buy', occ_symbol: `NVDA${EXPIRATION.slice(2, 10).replace(/-/g, '')}C00143000`, underlying: 'NVDA', option_type: 'call', strike: '143.00', expiration: EXPIRATION, position_intent: 'buy_to_open', ratio_qty: 1, quote_bid: '1.55', quote_ask: '1.70', delta: '0.141', gamma: '0.011', theta: '-0.055', vega: '0.132', open_interest: 8760, volume: 1310, quote_time: QUOTE_TIME },
]

export const NVDA_IRON_CONDOR: Strategy = {
  id: 'strategy-nvda-ic',
  agent_run_id: AGENT_RUN_ID,
  symbol: 'NVDA',
  strategy_type: 'iron_condor',
  regime: 'neutral',
  thesis:
    'NVDA implied volatility percentile is elevated (81st) heading into a quiet catalyst window; an iron condor monetizes rich premium on a mean-reversion view within a defined range.',
  confidence: '0.76',
  net_debit: null,
  net_credit: '1.35',
  max_profit: '135.00',
  max_loss: '365.00',
  breakevens: ['118.65', '139.35'],
  probability_of_profit: '0.70',
  liquidity_score: '0.85',
  score: '0.79',
  score_breakdown: {
    deterministic: [
      { label: 'Liquidity (OI/volume/spread)', weight: 0.25, value: 0.85 },
      { label: 'Risk/reward ratio', weight: 0.2, value: 0.62 },
      { label: 'Probability of profit', weight: 0.2, value: 0.7 },
      { label: 'Quote freshness', weight: 0.1, value: 0.97 },
    ],
    model_judgment: [
      { label: 'IV percentile vs. realized', weight: 0.15, value: 0.88 },
      { label: 'Range-bound behavior confidence', weight: 0.1, value: 0.66 },
    ],
    total: 0.76,
  },
  quote_timestamp: QUOTE_TIME,
  dte: DTE,
  rank: 2,
  selected: false,
  legs: NVDA_LEGS,
}

const NVDA_EVIDENCE: EvidenceSection[] = [
  { key: 'market_conditions', title: 'Market Conditions', body: 'NVDA has traded in a defined range over the past 3 weeks with no confirmed breakout in either direction.' },
  { key: 'technical_signals', title: 'Technical Signals', body: 'Bollinger Band width is elevated relative to price range, consistent with a volatility-selling setup.', bullets: ['ADX(14) at 16 -- weak trend strength', 'Price oscillating between 120 and 138 support/resistance'] },
  { key: 'volatility', title: 'Volatility', body: 'IV percentile of 81 is rich relative to trailing realized volatility, favoring premium-selling structures.' },
  { key: 'liquidity', title: 'Liquidity', body: 'All 4 legs trade on active markets with acceptable bid/ask spreads relative to mid.' },
  { key: 'risk_analysis', title: 'Risk Analysis', body: 'Max loss of $365 (3.65% of a single-contract allocation) is within per-trade risk limits; this proposal is awaiting human approval per the current approval_required autonomy mode.' },
  { key: 'expected_outcome', title: 'Expected Outcome (est.)', body: 'Model estimates a 70% probability of profit if NVDA remains between the two breakeven strikes through expiration -- an estimate, not a guarantee.' },
  { key: 'invalidation', title: 'Invalidation Conditions', body: 'Thesis is invalidated by a decisive close outside the 118.65-139.35 range or an unscheduled volatility catalyst.' },
]

// --- TSLA Covered Call (rejected by Risk Guardian) --------------------------

const TSLA_LEGS: StrategyLeg[] = [
  { id: 'leg-tsla-1', strategy_id: 'strategy-tsla-cc', action: 'sell', occ_symbol: `TSLA${EXPIRATION.slice(2, 10).replace(/-/g, '')}C00260000`, underlying: 'TSLA', option_type: 'call', strike: '260.00', expiration: EXPIRATION, position_intent: 'sell_to_open', ratio_qty: 1, quote_bid: '6.10', quote_ask: '6.35', delta: '0.331', gamma: '0.009', theta: '-0.121', vega: '0.288', open_interest: 14220, volume: 4110, quote_time: QUOTE_TIME },
]

export const TSLA_COVERED_CALL: Strategy = {
  id: 'strategy-tsla-cc',
  agent_run_id: AGENT_RUN_ID,
  symbol: 'TSLA',
  strategy_type: 'covered_call',
  regime: 'bullish',
  thesis:
    'TSLA covered call against a modeled 100-share equivalent position, harvesting premium while retaining upside to the short strike.',
  confidence: '0.81',
  net_debit: null,
  net_credit: '6.20',
  max_profit: '620.00',
  max_loss: '19380.00',
  breakevens: ['238.80'],
  probability_of_profit: '0.69',
  liquidity_score: '0.88',
  score: '0.81',
  score_breakdown: {
    deterministic: [
      { label: 'Liquidity (OI/volume/spread)', weight: 0.25, value: 0.88 },
      { label: 'Risk/reward ratio', weight: 0.2, value: 0.58 },
      { label: 'Probability of profit', weight: 0.2, value: 0.69 },
      { label: 'Quote freshness', weight: 0.1, value: 0.96 },
    ],
    model_judgment: [
      { label: 'Trend continuation confidence', weight: 0.15, value: 0.74 },
      { label: 'Earnings-window risk', weight: 0.1, value: 0.6 },
    ],
    total: 0.81,
  },
  quote_timestamp: QUOTE_TIME,
  dte: DTE,
  rank: 3,
  selected: false,
  legs: TSLA_LEGS,
}

const TSLA_EVIDENCE: EvidenceSection[] = [
  { key: 'market_conditions', title: 'Market Conditions', body: 'TSLA has rallied sharply over the trailing month, increasing concentration in an already sizeable modeled position.' },
  { key: 'technical_signals', title: 'Technical Signals', body: 'Momentum remains positive but is extended relative to its 20-day average.' },
  { key: 'volatility', title: 'Volatility', body: 'IV percentile at 54 -- moderate, supportive of a covered-call premium harvest.' },
  { key: 'liquidity', title: 'Liquidity', body: 'Short call leg trades on a deep, tight market.' },
  { key: 'risk_analysis', title: 'Risk Analysis', body: 'Rejected: modeled single-symbol exposure for TSLA would reach 11.4% of equity, exceeding the max_symbol_exposure_pct_equity limit of 10%.' },
  { key: 'expected_outcome', title: 'Expected Outcome (est.)', body: 'Not applicable -- proposal blocked before reaching an executable state.' },
  { key: 'invalidation', title: 'Invalidation Conditions', body: 'Would need existing TSLA exposure reduced, or a lower-delta strike selected, before resubmission.' },
]

export const STRATEGIES: Strategy[] = [SPY_BULL_CALL_SPREAD, NVDA_IRON_CONDOR, TSLA_COVERED_CALL]

const EVIDENCE_BY_ID: Record<string, EvidenceSection[]> = {
  'strategy-spy-bcs': SPY_EVIDENCE,
  'strategy-nvda-ic': NVDA_EVIDENCE,
  'strategy-tsla-cc': TSLA_EVIDENCE,
}

export function buildStrategyDetail(id: string): StrategyDetail | null {
  const strategy = STRATEGIES.find((s) => s.id === id)
  if (!strategy) return null
  return {
    ...strategy,
    legs: strategy.legs ?? [],
    evidence: EVIDENCE_BY_ID[id] ?? [],
    audit: {
      run_id: AGENT_RUN_ID,
      prompt_version: 'options-strategy@2026.08.1',
      config_version: 1,
      model_provider: 'anthropic',
      model_name: 'claude-sonnet',
      tools_used: ['market_data.get_snapshot', 'options_chain.get_quotes', 'risk_policy.get_active'],
      risk_policy_version: 1,
      trace_url: `/decisions?run_id=${AGENT_RUN_ID}`,
    },
  }
}

function buildPayoffPoints(centerPrice: number, isCredit: boolean, netAmount: number, _breakevens: number[], strikes: number[]): PayoffPoint[] {
  const points: PayoffPoint[] = []
  const low = centerPrice * 0.85
  const high = centerPrice * 1.15
  const step = (high - low) / 60
  for (let p = low; p <= high; p += step) {
    let pnl = 0
    if (strikes.length === 2 && !isCredit) {
      // vertical debit spread
      const [k1, k2] = strikes as [number, number]
      const intrinsic = Math.max(0, Math.min(p, k2) - k1)
      pnl = (intrinsic - netAmount) * 100
    } else if (strikes.length === 4) {
      // iron condor
      const [p1, p2, c1, c2] = strikes as [number, number, number, number]
      const putLoss = Math.max(0, p2 - p) - Math.max(0, p1 - p)
      const callLoss = Math.max(0, p - c1) - Math.max(0, p - c2)
      pnl = (netAmount - putLoss - callLoss) * 100
    } else if (strikes.length === 1) {
      // covered call short leg only (approximation of combined P&L incl. modeled shares)
      const k = strikes[0] as number
      const shareGain = p - centerPrice
      const callLoss = Math.max(0, p - k)
      pnl = (shareGain - callLoss + netAmount) * 100
    }
    points.push({ underlying_price: p.toFixed(2), pnl: pnl.toFixed(2) })
  }
  return points
}

export function buildPayoffCurve(id: string): PayoffCurve | null {
  if (id === 'strategy-spy-bcs') {
    const points = buildPayoffPoints(585, false, 8.6, [578.6], [570, 600])
    return { points, breakevens: ['578.60'], max_profit: '2140.00', max_loss: '860.00', current_price: '585.00' }
  }
  if (id === 'strategy-nvda-ic') {
    const points = buildPayoffPoints(128, true, 1.35, [118.65, 139.35], [115, 120, 138, 143])
    return { points, breakevens: ['118.65', '139.35'], max_profit: '135.00', max_loss: '365.00', current_price: '128.00' }
  }
  if (id === 'strategy-tsla-cc') {
    const points = buildPayoffPoints(245, true, 6.2, [238.8], [260])
    return { points, breakevens: ['238.80'], max_profit: '620.00', max_loss: '19380.00', current_price: '245.00' }
  }
  return null
}
