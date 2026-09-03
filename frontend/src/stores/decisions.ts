import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as decisionsService from '@/services/decisions'
import { useUiNotificationsStore } from './uiNotifications'
import type { AiDecision, DecisionFilters, DecisionTrace } from '@/types/api'

export const useDecisionsStore = defineStore('decisions', () => {
  const decisions = ref<AiDecision[]>([])
  const filters = ref<DecisionFilters>({})
  const traces = ref<Record<string, DecisionTrace>>({})
  const expandedRunIds = ref<Set<string>>(new Set())

  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function loadDecisions(nextFilters: DecisionFilters = filters.value): Promise<void> {
    const ui = useUiNotificationsStore()
    filters.value = nextFilters
    isLoading.value = true
    error.value = null
    try {
      const result = await decisionsService.fetchDecisions(nextFilters)
      decisions.value = result.data
      ui.setFallback('decisions', result.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load decisions.'
    } finally {
      isLoading.value = false
    }
  }

  async function loadTrace(decisionId: string): Promise<DecisionTrace | null> {
    if (traces.value[decisionId]) return traces.value[decisionId] ?? null
    try {
      const result = await decisionsService.fetchDecisionTrace(decisionId)
      traces.value = { ...traces.value, [decisionId]: result.data }
      return result.data
    } catch {
      return null
    }
  }

  function toggleExpanded(runId: string): void {
    const next = new Set(expandedRunIds.value)
    if (next.has(runId)) next.delete(runId)
    else next.add(runId)
    expandedRunIds.value = next
  }

  function exportTraceJson(decisionId: string): string {
    const trace = traces.value[decisionId]
    const decision = decisions.value.find((d) => d.id === decisionId)
    return JSON.stringify({ decision, trace }, null, 2)
  }

  return { decisions, filters, traces, expandedRunIds, isLoading, error, loadDecisions, loadTrace, toggleExpanded, exportTraceJson }
})
