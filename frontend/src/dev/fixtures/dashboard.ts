import type { ActivityEntry, DashboardSummary, LatestDecisionSummary } from '@/types/api'
import { AGENT_LOGS } from './agents'
import { DEMO_NUMBERS, isoDaysFromAnchor } from './demo'
import { SPY_PROPOSAL } from './proposals'
import { SPY_BULL_CALL_SPREAD } from './strategies'

export const LATEST_DECISION: LatestDecisionSummary = {
  decision_id: 'decision-spy-bcs',
  trade_proposal_id: SPY_PROPOSAL.id,
  symbol: SPY_BULL_CALL_SPREAD.symbol,
  strategy_type: SPY_BULL_CALL_SPREAD.strategy_type,
  regime: SPY_BULL_CALL_SPREAD.regime,
  confidence: SPY_BULL_CALL_SPREAD.confidence,
  thesis: SPY_BULL_CALL_SPREAD.thesis,
  status: SPY_PROPOSAL.status,
  created_at: SPY_PROPOSAL.created_at,
}

export const DASHBOARD_SUMMARY: DashboardSummary = {
  balance: DEMO_NUMBERS.balance,
  total_pl: DEMO_NUMBERS.totalPl,
  unrealized_pl: DEMO_NUMBERS.unrealizedPl,
  realized_pl: DEMO_NUMBERS.realizedPl,
  buying_power: DEMO_NUMBERS.buyingPower,
  risk_level: DEMO_NUMBERS.riskLevel,
  starting_equity: DEMO_NUMBERS.startingEquity,
  as_of: isoDaysFromAnchor(0),
  latest_decision: LATEST_DECISION,
}

export const DASHBOARD_ACTIVITY: ActivityEntry[] = (Object.keys(AGENT_LOGS) as (keyof typeof AGENT_LOGS)[])
  .flatMap((type): ActivityEntry[] =>
    AGENT_LOGS[type].map((log) => ({
      id: `activity-${log.id}`,
      agent_type: type,
      message: log.message,
      created_at: log.created_at,
      level: log.level,
      run_id: log.agent_run_id,
    })),
  )
  .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
