import type { Agent, AgentDetail, AgentLogEntry, AgentRunDetail, AgentRunSummary, AgentType } from '@/types/api'
import { isoDaysFromAnchor } from './demo'

export const AGENT_RUN_ID = 'run-2026-08-30-0001'

export const SEEDED_AGENT_RUN: AgentRunSummary = {
  id: AGENT_RUN_ID,
  trigger: 'scheduled',
  mode: 'approval_required',
  status: 'completed',
  started_at: isoDaysFromAnchor(0),
  completed_at: isoDaysFromAnchor(0),
  summary: 'Full pipeline run across SPY, NVDA, TSLA -- 3 strategies produced, 1 executed, 1 pending approval, 1 rejected by Risk Guardian.',
  symbol_universe: ['SPY', 'NVDA', 'TSLA'],
}

const AGENT_META: Record<AgentType, { name: string; objective: string; task: string }> = {
  market_analyst: {
    name: 'Market Analyst',
    objective: 'Scans market/options data for regime, volatility, and sentiment signals to surface tradeable setups.',
    task: 'Awaiting next scheduled scan window.',
  },
  options_strategy: {
    name: 'Options Strategy',
    objective: 'Builds and scores multi-leg options strategies from Market Analyst signals.',
    task: 'Idle -- last batch produced 3 ranked strategies.',
  },
  risk_guardian: {
    name: 'Risk Guardian',
    objective: 'Evaluates every proposal against the active risk policy before it can reach execution.',
    task: 'Monitoring open exposure against policy v1 limits.',
  },
  execution: {
    name: 'Execution',
    objective: 'Submits approved trades to Alpaca paper trading and reconciles fills.',
    task: 'Idle -- no orders in flight.',
  },
  learning: {
    name: 'Learning',
    objective: 'Aggregates closed-trade outcomes into win-rate, profit-factor, and calibration feedback.',
    task: 'Idle -- last aggregation included 2 closed trades.',
  },
}

export const AGENTS: Agent[] = (Object.keys(AGENT_META) as AgentType[]).map((type, i) => ({
  id: `agent-${type}`,
  type,
  name: AGENT_META[type].name,
  enabled: true,
  status: 'idle',
  config_version: 1,
  current_task: AGENT_META[type].task,
  last_heartbeat_at: isoDaysFromAnchor(-((i + 1) / 48)),
  decisions_today: type === 'market_analyst' ? 3 : type === 'options_strategy' ? 3 : type === 'risk_guardian' ? 3 : type === 'execution' ? 1 : 1,
  avg_latency_ms: { market_analyst: 1840, options_strategy: 2650, risk_guardian: 610, execution: 1120, learning: 340 }[type],
}))

function logsFor(type: AgentType): AgentLogEntry[] {
  const base: Record<AgentType, { level: AgentLogEntry['level']; message: string }[]> = {
    market_analyst: [
      { level: 'info', message: 'Pulled market snapshots for SPY, NVDA, TSLA (15m bars, 90d lookback).' },
      { level: 'info', message: 'Detected bullish regime on SPY (trend + momentum confluence, IV percentile 38).' },
      { level: 'info', message: 'Flagged NVDA for elevated IV percentile (81) ahead of earnings window -- neutral regime candidate.' },
      { level: 'warn', message: 'TSLA sentiment mixed; proceeding with reduced confidence weighting.' },
    ],
    options_strategy: [
      { level: 'info', message: 'Constructed Bull Call Spread on SPY: buy 1% OTM call / sell 6% OTM call, 30 DTE.' },
      { level: 'info', message: 'Constructed Iron Condor on NVDA targeting high-IV mean reversion, 30 DTE.' },
      { level: 'info', message: 'Constructed Covered Call on TSLA against modeled 100-share equivalent exposure.' },
      { level: 'info', message: 'Ranked 3 strategies by composite score; SPY Bull Call Spread ranked #1 at 87% confidence.' },
    ],
    risk_guardian: [
      { level: 'info', message: 'Evaluated SPY Bull Call Spread proposal against policy v1 -- approved, risk score 18.' },
      { level: 'warn', message: 'Evaluated NVDA Iron Condor proposal -- approval_required (confidence just above threshold, escalating for human sign-off).' },
      { level: 'error', message: 'Rejected TSLA Covered Call proposal -- max_symbol_exposure_pct_equity would be exceeded (11.4% observed vs 10% limit).' },
    ],
    execution: [
      { level: 'info', message: 'Submitted SPY Bull Call Spread order to Alpaca paper (order_class=mleg, 1 contract per leg).' },
      { level: 'info', message: 'Order filled: both legs confirmed, position opened.' },
    ],
    learning: [
      { level: 'info', message: 'Aggregated 2 closed trades: 1 win, 1 loss. Win rate 50%, profit factor computed from ledger.' },
      { level: 'info', message: 'Updated calibration notes for options_strategy confidence scoring.' },
    ],
  }
  return base[type].map((entry, i) => ({
    id: `log-${type}-${i}`,
    agent_run_id: AGENT_RUN_ID,
    agent_type: type,
    level: entry.level,
    message: entry.message,
    context: {},
    created_at: isoDaysFromAnchor(-((base[type].length - i) / 96)),
  }))
}

export const AGENT_LOGS: Record<AgentType, AgentLogEntry[]> = {
  market_analyst: logsFor('market_analyst'),
  options_strategy: logsFor('options_strategy'),
  risk_guardian: logsFor('risk_guardian'),
  execution: logsFor('execution'),
  learning: logsFor('learning'),
}

export function buildAgentDetail(type: AgentType): AgentDetail {
  const agent = AGENTS.find((a) => a.type === type)
  if (!agent) throw new Error(`Unknown agent type: ${type}`)
  return {
    ...agent,
    objective: AGENT_META[type].objective,
    current_run: null,
    performance_trend: Array.from({ length: 14 }, (_, i) => ({
      t: isoDaysFromAnchor(i - 13),
      value: 60 + Math.round(Math.sin(i / 2.3) * 12 + i * 1.4),
    })),
    recent_decisions: [],
  }
}

export const AGENT_RUN_DETAIL: AgentRunDetail = {
  ...SEEDED_AGENT_RUN,
  trace_id: 'trace-2026-08-30-0001',
  nodes: [
    { agent_type: 'market_analyst', status: 'completed', started_at: isoDaysFromAnchor(0), completed_at: isoDaysFromAnchor(0), summary: 'Signals generated for 3 symbols.' },
    { agent_type: 'options_strategy', status: 'completed', started_at: isoDaysFromAnchor(0), completed_at: isoDaysFromAnchor(0), summary: '3 strategies constructed and ranked.' },
    { agent_type: 'risk_guardian', status: 'completed', started_at: isoDaysFromAnchor(0), completed_at: isoDaysFromAnchor(0), summary: '1 approved, 1 approval_required, 1 rejected.' },
    { agent_type: 'execution', status: 'completed', started_at: isoDaysFromAnchor(0), completed_at: isoDaysFromAnchor(0), summary: 'SPY spread submitted and filled.' },
    { agent_type: 'learning', status: 'completed', started_at: isoDaysFromAnchor(0), completed_at: isoDaysFromAnchor(0), summary: 'Ledger aggregation updated.' },
  ],
}
