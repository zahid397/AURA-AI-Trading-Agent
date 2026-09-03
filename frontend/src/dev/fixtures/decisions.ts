import type { AiDecision, DecisionTrace } from '@/types/api'
import { AGENT_RUN_ID } from './agents'
import { isoDaysFromAnchor } from './demo'
import { SPY_ORDER } from './orders'
import { NVDA_RULE_RESULTS, SPY_RULE_RESULTS, TSLA_RULE_RESULTS } from './risk'
import { NVDA_IRON_CONDOR, SPY_BULL_CALL_SPREAD, TSLA_COVERED_CALL } from './strategies'

const MODEL = { provider: 'anthropic', name: 'claude-sonnet', promptVersion: 'options-strategy@2026.08.1' }

export const AI_DECISIONS: AiDecision[] = [
  {
    id: 'decision-spy-bcs',
    agent_run_id: AGENT_RUN_ID,
    strategy_id: SPY_BULL_CALL_SPREAD.id,
    symbol: 'SPY',
    agent_type: 'options_strategy',
    thesis: SPY_BULL_CALL_SPREAD.thesis,
    confidence: SPY_BULL_CALL_SPREAD.confidence,
    model_provider: MODEL.provider,
    model_name: MODEL.name,
    prompt_version: MODEL.promptVersion,
    outcome: 'executed',
    status: 'completed',
    created_at: isoDaysFromAnchor(0),
  },
  {
    id: 'decision-nvda-ic',
    agent_run_id: AGENT_RUN_ID,
    strategy_id: NVDA_IRON_CONDOR.id,
    symbol: 'NVDA',
    agent_type: 'options_strategy',
    thesis: NVDA_IRON_CONDOR.thesis,
    confidence: NVDA_IRON_CONDOR.confidence,
    model_provider: MODEL.provider,
    model_name: MODEL.name,
    prompt_version: MODEL.promptVersion,
    outcome: 'pending',
    status: 'awaiting_approval',
    created_at: isoDaysFromAnchor(0),
  },
  {
    id: 'decision-tsla-cc',
    agent_run_id: AGENT_RUN_ID,
    strategy_id: TSLA_COVERED_CALL.id,
    symbol: 'TSLA',
    agent_type: 'risk_guardian',
    thesis: TSLA_COVERED_CALL.thesis,
    confidence: TSLA_COVERED_CALL.confidence,
    model_provider: MODEL.provider,
    model_name: MODEL.name,
    prompt_version: MODEL.promptVersion,
    outcome: 'rejected',
    status: 'rejected',
    created_at: isoDaysFromAnchor(0),
  },
]

function buildTrace(decisionId: string): DecisionTrace | null {
  const decision = AI_DECISIONS.find((d) => d.id === decisionId)
  if (!decision) return null
  const ruleMap = { 'decision-spy-bcs': SPY_RULE_RESULTS, 'decision-nvda-ic': NVDA_RULE_RESULTS, 'decision-tsla-cc': TSLA_RULE_RESULTS } as const
  const rules = ruleMap[decisionId as keyof typeof ruleMap] ?? []
  return {
    run_id: decision.agent_run_id,
    decision_id: decisionId,
    rationale_summary: decision.thesis,
    nodes: [
      {
        agent_type: 'market_analyst',
        step: 'signal_generation',
        started_at: isoDaysFromAnchor(0),
        completed_at: isoDaysFromAnchor(0),
        inputs: { symbols: [decision.symbol] },
        outputs: { regime: 'detected', iv_percentile: 'computed' },
        tool_calls: [{ tool: 'market_data.get_snapshot', args_summary: `symbol=${decision.symbol}`, result_summary: 'snapshot returned' }],
        errors: [],
      },
      {
        agent_type: 'options_strategy',
        step: 'strategy_construction',
        started_at: isoDaysFromAnchor(0),
        completed_at: isoDaysFromAnchor(0),
        inputs: { symbol: decision.symbol },
        outputs: { thesis: decision.thesis, confidence: decision.confidence },
        tool_calls: [{ tool: 'options_chain.get_quotes', args_summary: `symbol=${decision.symbol}`, result_summary: 'legs priced' }],
        errors: [],
      },
      {
        agent_type: 'risk_guardian',
        step: 'risk_evaluation',
        started_at: isoDaysFromAnchor(0),
        completed_at: isoDaysFromAnchor(0),
        inputs: { strategy_id: decision.strategy_id },
        outputs: { result: decision.outcome === 'rejected' ? 'rejected' : decision.outcome === 'pending' ? 'approval_required' : 'approved' },
        risk_rules: rules,
        tool_calls: [{ tool: 'risk_policy.get_active', args_summary: 'version=1', result_summary: 'policy v1 applied' }],
        errors: [],
      },
      ...(decision.outcome === 'executed'
        ? [
            {
              agent_type: 'execution' as const,
              step: 'order_submission',
              started_at: isoDaysFromAnchor(0),
              completed_at: isoDaysFromAnchor(0),
              inputs: { proposal_id: 'proposal-spy' },
              outputs: { order_id: SPY_ORDER.id, status: SPY_ORDER.status },
              tool_calls: [{ tool: 'alpaca.submit_order', args_summary: 'order_class=mleg', result_summary: 'filled' }],
              errors: [],
            },
          ]
        : []),
    ],
    order_result: decision.outcome === 'executed' ? SPY_ORDER : null,
    portfolio_result: decision.outcome === 'executed' ? { equity_after: '100245.69', position_id: 'position-spy-bcs' } : null,
  }
}

export const DECISION_TRACES: Record<string, DecisionTrace> = Object.fromEntries(
  AI_DECISIONS.map((d) => [d.id, buildTrace(d.id)] as const).filter((pair): pair is [string, DecisionTrace] => pair[1] !== null),
)
