import type { RiskEvaluation, TradeProposal } from '@/types/api'
import { AGENT_RUN_ID } from './agents'
import { DEMO_USER, isoDaysFromAnchor } from './demo'
import { NVDA_RULE_RESULTS, RISK_POLICY, SPY_RULE_RESULTS, TSLA_RULE_RESULTS } from './risk'
import { NVDA_IRON_CONDOR, SPY_BULL_CALL_SPREAD, TSLA_COVERED_CALL } from './strategies'

const SPY_RISK_EVAL: RiskEvaluation = {
  id: 'risk-eval-spy',
  trade_proposal_id: 'proposal-spy',
  risk_policy_id: RISK_POLICY.id,
  risk_policy_version: RISK_POLICY.version,
  result: 'approved',
  risk_score: 18,
  evaluated_at: isoDaysFromAnchor(0),
  rule_results: SPY_RULE_RESULTS,
}

const NVDA_RISK_EVAL: RiskEvaluation = {
  id: 'risk-eval-nvda',
  trade_proposal_id: 'proposal-nvda',
  risk_policy_id: RISK_POLICY.id,
  risk_policy_version: RISK_POLICY.version,
  result: 'approval_required',
  risk_score: 34,
  evaluated_at: isoDaysFromAnchor(0),
  rule_results: NVDA_RULE_RESULTS,
}

const TSLA_RISK_EVAL: RiskEvaluation = {
  id: 'risk-eval-tsla',
  trade_proposal_id: 'proposal-tsla',
  risk_policy_id: RISK_POLICY.id,
  risk_policy_version: RISK_POLICY.version,
  result: 'rejected',
  risk_score: 72,
  evaluated_at: isoDaysFromAnchor(0),
  rule_results: TSLA_RULE_RESULTS,
}

export const SPY_PROPOSAL: TradeProposal = {
  id: 'proposal-spy',
  agent_run_id: AGENT_RUN_ID,
  strategy_id: SPY_BULL_CALL_SPREAD.id,
  user_id: DEMO_USER.id,
  status: 'executed',
  risk_evaluation_id: SPY_RISK_EVAL.id,
  expires_at: isoDaysFromAnchor(0.2),
  approved_at: isoDaysFromAnchor(0),
  approved_by: null,
  rejected_reason: null,
  created_at: isoDaysFromAnchor(0),
  strategy: SPY_BULL_CALL_SPREAD,
  risk_evaluation: SPY_RISK_EVAL,
}

export const NVDA_PROPOSAL: TradeProposal = {
  id: 'proposal-nvda',
  agent_run_id: AGENT_RUN_ID,
  strategy_id: NVDA_IRON_CONDOR.id,
  user_id: DEMO_USER.id,
  status: 'pending',
  risk_evaluation_id: NVDA_RISK_EVAL.id,
  expires_at: isoDaysFromAnchor(1),
  approved_at: null,
  approved_by: null,
  rejected_reason: null,
  created_at: isoDaysFromAnchor(0),
  strategy: NVDA_IRON_CONDOR,
  risk_evaluation: NVDA_RISK_EVAL,
}

export const TSLA_PROPOSAL: TradeProposal = {
  id: 'proposal-tsla',
  agent_run_id: AGENT_RUN_ID,
  strategy_id: TSLA_COVERED_CALL.id,
  user_id: DEMO_USER.id,
  status: 'rejected',
  risk_evaluation_id: TSLA_RISK_EVAL.id,
  expires_at: isoDaysFromAnchor(0),
  approved_at: null,
  approved_by: null,
  rejected_reason: 'Blocked by Risk Guardian: max_symbol_exposure_pct_equity would be exceeded (11.4% observed vs 10% limit).',
  created_at: isoDaysFromAnchor(0),
  strategy: TSLA_COVERED_CALL,
  risk_evaluation: TSLA_RISK_EVAL,
}

export const TRADE_PROPOSALS: TradeProposal[] = [SPY_PROPOSAL, NVDA_PROPOSAL, TSLA_PROPOSAL]
