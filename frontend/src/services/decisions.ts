import type { AiDecision, DecisionFilters, DecisionTrace } from '@/types/api'
import { AI_DECISIONS, DECISION_TRACES } from '@/dev/fixtures/decisions'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get } from './http'

function matchesFilters(decision: AiDecision, filters: DecisionFilters): boolean {
  if (filters.symbol && decision.symbol !== filters.symbol) return false
  if (filters.agent && decision.agent_type !== filters.agent) return false
  if (filters.outcome && decision.outcome !== filters.outcome) return false
  if (filters.min_confidence && Number.parseFloat(decision.confidence) < filters.min_confidence) return false
  if (filters.status && decision.status !== filters.status) return false
  return true
}

export function fetchDecisions(filters: DecisionFilters = {}): Promise<ServiceResult<AiDecision[]>> {
  return withFixtureFallback(
    () => get<AiDecision[]>('/decisions', { params: filters }),
    () => AI_DECISIONS.filter((d) => matchesFilters(d, filters)),
  )
}

export function fetchDecision(id: string): Promise<ServiceResult<AiDecision>> {
  return withFixtureFallback(
    () => get<AiDecision>(`/decisions/${id}`),
    () => {
      const decision = AI_DECISIONS.find((d) => d.id === id) ?? AI_DECISIONS[0]
      if (!decision) throw new Error('No fixture decision available')
      return decision
    },
  )
}

export function fetchDecisionTrace(id: string): Promise<ServiceResult<DecisionTrace>> {
  return withFixtureFallback(
    () => get<DecisionTrace>(`/decisions/${id}/trace`),
    () => {
      const trace = DECISION_TRACES[id]
      if (!trace) throw new Error(`No fixture trace for decision ${id}`)
      return trace
    },
  )
}
