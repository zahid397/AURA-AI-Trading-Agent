import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as agentsService from '@/services/agents'
import { useUiNotificationsStore } from './uiNotifications'
import type { Agent, AgentDetail, AgentLogEntry, AgentRunDetail, AgentRunStatus, AgentType } from '@/types/api'

const POLL_INTERVAL_MS = 2000
// ~3 minutes -- generous for a real multi-agent pipeline, but bounded: without
// a ceiling here, a run that never reaches a terminal status server-side (a
// hung/crashed graph node with nothing reporting failure back) left this loop
// polling forever with no signal to the user. Confirmed live: a run stuck at
// `running` polled continuously for 7+ minutes before this fix.
const MAX_POLL_ATTEMPTS = 90
const TERMINAL_RUN_STATUSES: AgentRunStatus[] = ['completed', 'failed', 'cancelled', 'rejected', 'awaiting_approval']

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export const useAgentsStore = defineStore('agents', () => {
  const agents = ref<Agent[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  const selectedType = ref<AgentType | null>(null)
  const selectedDetail = ref<AgentDetail | null>(null)
  const selectedLogs = ref<AgentLogEntry[]>([])
  const isLoadingDetail = ref(false)

  const activeRun = ref<AgentRunDetail | null>(null)
  const activeRunId = ref<string | null>(null)
  const isRunActive = ref(false)

  async function loadAgents(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const result = await agentsService.fetchAgents()
      agents.value = result.data
      ui.setFallback('agents-list', result.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load agents.'
    } finally {
      isLoading.value = false
    }
  }

  async function selectAgent(type: AgentType): Promise<void> {
    selectedType.value = type
    isLoadingDetail.value = true
    try {
      const [detail, logs] = await Promise.all([agentsService.fetchAgentDetail(type), agentsService.fetchAgentLogs(type)])
      selectedDetail.value = detail.data
      selectedLogs.value = logs.data
    } catch {
      selectedDetail.value = null
      selectedLogs.value = []
    } finally {
      isLoadingDetail.value = false
    }
  }

  function clearSelection(): void {
    selectedType.value = null
    selectedDetail.value = null
    selectedLogs.value = []
  }

  async function toggleAgentEnabled(type: AgentType, enabled: boolean): Promise<void> {
    const ui = useUiNotificationsStore()
    try {
      const updated = await agentsService.setAgentEnabled(type, enabled)
      const idx = agents.value.findIndex((a) => a.type === type)
      if (idx !== -1) agents.value.splice(idx, 1, updated)
      ui.success(enabled ? `${updated.name} resumed.` : `${updated.name} paused.`)
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to update agent state.')
    }
  }

  async function pauseAll(): Promise<void> {
    await Promise.all(agents.value.filter((a) => a.enabled).map((a) => toggleAgentEnabled(a.type, false)))
  }

  async function runAnalysis(symbols: string[]): Promise<void> {
    const ui = useUiNotificationsStore()
    isRunActive.value = true
    try {
      const { id } = await agentsService.startAgentRun(symbols)
      activeRunId.value = id
      ui.success(`Analysis run started for ${symbols.join(', ')}.`)
      await pollRun(id)
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to start analysis run.')
      isRunActive.value = false
    }
  }

  /** Polls GET /agent-runs/{id} until the run reaches a terminal status, keeping `activeRun` current. */
  async function pollRun(runId: string): Promise<void> {
    const ui = useUiNotificationsStore()
    try {
      let attempts = 0
      while (isRunActive.value && activeRunId.value === runId) {
        const detail = await agentsService.fetchAgentRun(runId)
        activeRun.value = detail.data
        if (TERMINAL_RUN_STATUSES.includes(detail.data.status)) return
        attempts += 1
        if (attempts >= MAX_POLL_ATTEMPTS) {
          ui.error('This run is taking much longer than expected and may be stuck. You can try again.')
          return
        }
        await wait(POLL_INTERVAL_MS)
      }
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Lost track of the analysis run.')
    } finally {
      isRunActive.value = false
    }
  }

  async function cancelRun(): Promise<void> {
    const ui = useUiNotificationsStore()
    const runId = activeRunId.value
    if (!runId) return
    try {
      await agentsService.cancelAgentRun(runId)
      isRunActive.value = false
      if (activeRun.value) activeRun.value = { ...activeRun.value, status: 'cancelled' }
      ui.info('Analysis run cancelled.')
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to cancel analysis run.')
    }
  }

  return {
    agents,
    isLoading,
    error,
    selectedType,
    selectedDetail,
    selectedLogs,
    isLoadingDetail,
    activeRun,
    activeRunId,
    isRunActive,
    loadAgents,
    selectAgent,
    clearSelection,
    toggleAgentEnabled,
    pauseAll,
    runAnalysis,
    cancelRun,
  }
})
