import type { Agent, AgentDetail, AgentLogEntry, AgentRunDetail, AgentType } from '@/types/api'
import { AGENT_LOGS, AGENT_RUN_DETAIL, AGENTS, buildAgentDetail } from '@/dev/fixtures/agents'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, patch, post } from './http'
import { runMutation } from './mutation'

export function fetchAgents(): Promise<ServiceResult<Agent[]>> {
  return withFixtureFallback(
    () => get<Agent[]>('/agents'),
    () => AGENTS,
  )
}

export function fetchAgentDetail(type: AgentType): Promise<ServiceResult<AgentDetail>> {
  return withFixtureFallback(
    () => get<AgentDetail>(`/agents/${type}`),
    () => buildAgentDetail(type),
  )
}

export function fetchAgentLogs(type: AgentType): Promise<ServiceResult<AgentLogEntry[]>> {
  return withFixtureFallback(
    () => get<AgentLogEntry[]>(`/agents/${type}/logs`),
    () => AGENT_LOGS[type],
  )
}

export function setAgentEnabled(type: AgentType, enabled: boolean): Promise<Agent> {
  return runMutation(
    () => patch<Agent>(`/agents/${type}/state`, { enabled }),
    () => {
      const agent = AGENTS.find((a) => a.type === type)
      if (!agent) throw new Error(`Unknown agent ${type}`)
      agent.enabled = enabled
      agent.status = enabled ? 'idle' : 'paused'
      return { ...agent }
    },
  )
}

export function startAgentRun(symbols: string[]): Promise<{ id: string }> {
  return runMutation(
    // The backend returns an AgentRunResource, keyed by `id` -- not `run_id`.
    () => post<{ id: string }>('/agent-runs', { trigger: 'manual', symbols }),
    () => ({ id: AGENT_RUN_DETAIL.id }),
  )
}

export function fetchAgentRun(runId: string): Promise<ServiceResult<AgentRunDetail>> {
  return withFixtureFallback(
    () => get<AgentRunDetail>(`/agent-runs/${runId}`),
    () => AGENT_RUN_DETAIL,
  )
}

export function cancelAgentRun(runId: string): Promise<void> {
  return runMutation(
    () => post<void>(`/agent-runs/${runId}/cancel`),
    () => undefined,
  )
}
