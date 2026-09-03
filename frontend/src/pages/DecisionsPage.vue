<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue'
import { Brain, ChevronDown, Download, GraduationCap, Radar, ShieldAlert, Zap } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import { useDecisionsStore } from '@/stores/decisions'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatDateTime, formatPercent } from '@/utils/format'
import type { AgentType, DecisionFilters } from '@/types/api'

const decisions = useDecisionsStore()
const ui = useUiNotificationsStore()

const AGENT_ICONS: Record<AgentType, typeof Radar> = {
  market_analyst: Radar,
  options_strategy: Brain,
  risk_guardian: ShieldAlert,
  execution: Zap,
  learning: GraduationCap,
}

const filterForm = reactive<DecisionFilters>({})

onMounted(() => void decisions.loadDecisions())

function applyFilters(): void {
  void decisions.loadDecisions({ ...filterForm })
}
function resetFilters(): void {
  filterForm.symbol = undefined
  filterForm.agent = undefined
  filterForm.outcome = undefined
  filterForm.min_confidence = undefined
  applyFilters()
}

const groups = computed(() => {
  const map = new Map<string, typeof decisions.decisions>()
  for (const d of decisions.decisions) {
    const list = map.get(d.agent_run_id) ?? []
    list.push(d)
    map.set(d.agent_run_id, list)
  }
  return Array.from(map.entries()).map(([runId, list]) => ({ runId, list }))
})

async function toggle(runId: string, decisionId: string): Promise<void> {
  decisions.toggleExpanded(`${runId}:${decisionId}`)
  await decisions.loadTrace(decisionId)
}

function isExpanded(runId: string, decisionId: string): boolean {
  return decisions.expandedRunIds.has(`${runId}:${decisionId}`)
}

function outcomeTone(outcome: string): 'success' | 'danger' | 'warning' | 'info' | 'neutral' {
  if (outcome === 'executed') return 'success'
  if (outcome === 'rejected') return 'danger'
  if (outcome === 'pending') return 'warning'
  return 'neutral'
}

function exportJson(decisionId: string): void {
  const json = decisions.exportTraceJson(decisionId)
  const blob = new Blob([json], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${decisionId}-trace.json`
  a.click()
  URL.revokeObjectURL(url)
  ui.success('Sanitized trace exported.')
}
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['decisions'] ?? null" />

    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
          <label for="f-symbol" class="mb-1 block text-xs font-medium text-slate-600">Symbol</label>
          <input id="f-symbol" v-model="filterForm.symbol" type="text" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600" placeholder="e.g. SPY" />
        </div>
        <div>
          <label for="f-agent" class="mb-1 block text-xs font-medium text-slate-600">Agent</label>
          <select id="f-agent" v-model="filterForm.agent" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600">
            <option :value="undefined">Any</option>
            <option value="market_analyst">Market Analyst</option>
            <option value="options_strategy">Options Strategy</option>
            <option value="risk_guardian">Risk Guardian</option>
            <option value="execution">Execution</option>
            <option value="learning">Learning</option>
          </select>
        </div>
        <div>
          <label for="f-outcome" class="mb-1 block text-xs font-medium text-slate-600">Outcome</label>
          <select id="f-outcome" v-model="filterForm.outcome" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600">
            <option :value="undefined">Any</option>
            <option value="executed">Executed</option>
            <option value="pending">Pending</option>
            <option value="rejected">Rejected</option>
            <option value="analyzed">Analyzed</option>
          </select>
        </div>
        <div>
          <label for="f-confidence" class="mb-1 block text-xs font-medium text-slate-600">Min confidence</label>
          <input id="f-confidence" v-model.number="filterForm.min_confidence" type="number" min="0" max="1" step="0.05" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600" />
        </div>
        <div class="flex items-end gap-2">
          <button type="button" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">Apply</button>
          <button type="button" class="rounded-control border border-border px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas" @click="resetFilters">Reset</button>
        </div>
      </div>
    </div>

    <div v-if="decisions.isLoading && decisions.decisions.length === 0" class="space-y-3">
      <Skeleton v-for="i in 3" :key="i" height="6rem" />
    </div>
    <ErrorRetry v-else-if="decisions.error" :message="decisions.error" @retry="() => decisions.loadDecisions()" />
    <EmptyState v-else-if="groups.length === 0" title="No decisions match these filters" action-label="Reset filters" @action="resetFilters" />
    <div v-else class="space-y-5">
      <div v-for="group in groups" :key="group.runId" class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-600">Run {{ group.runId }}</p>
        <ol class="space-y-3 border-l-2 border-border pl-4">
          <li v-for="decision in group.list" :key="decision.id" class="relative">
            <span class="absolute -left-[21px] top-1 flex h-3 w-3 items-center justify-center rounded-full bg-brand-600" aria-hidden="true" />
            <div class="flex flex-wrap items-center gap-2">
              <component :is="AGENT_ICONS[decision.agent_type]" :size="14" class="text-slate-600" />
              <p class="text-sm font-medium capitalize text-ink-950">{{ decision.agent_type?.replaceAll('_', ' ') ?? '--' }} · {{ decision.symbol }}</p>
              <Badge :tone="outcomeTone(decision.outcome)">{{ decision.outcome }}</Badge>
              <span class="text-xs text-slate-600">{{ formatDateTime(decision.created_at) }}</span>
              <span class="tabular-nums text-xs text-slate-600">{{ formatPercent(decision.confidence) }} confidence</span>
            </div>
            <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ decision.thesis }}</p>
            <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline" @click="toggle(group.runId, decision.id)">
              <ChevronDown :size="12" :class="{ 'rotate-180': isExpanded(group.runId, decision.id) }" class="transition-transform" />
              {{ isExpanded(group.runId, decision.id) ? 'Hide' : 'Show' }} trace detail
            </button>

            <div v-if="isExpanded(group.runId, decision.id)" class="mt-2 space-y-2 rounded-control border border-border bg-canvas p-3">
              <div v-if="!decisions.traces[decision.id]" class="space-y-2">
                <Skeleton height="1.5rem" />
                <Skeleton height="3rem" />
              </div>
              <template v-else>
                <p class="text-xs text-slate-600">{{ decisions.traces[decision.id]!.rationale_summary }}</p>
                <div v-for="node in decisions.traces[decision.id]!.nodes" :key="node.step" class="rounded-control bg-surface p-2 text-xs">
                  <div class="flex items-center justify-between">
                    <p class="font-medium capitalize text-ink-950">{{ node.agent_type?.replaceAll('_', ' ') ?? '--' }} — {{ node.step?.replaceAll('_', ' ') ?? '--' }}</p>
                    <span class="text-slate-600">{{ formatDateTime(node.started_at) }}</span>
                  </div>
                  <p v-if="node.tool_calls.length" class="mt-1 text-slate-600">
                    Tools: {{ node.tool_calls.map((t) => t.tool).join(', ') }}
                  </p>
                  <p v-if="node.risk_rules?.length" class="mt-1 text-slate-600">
                    Risk rules: {{ node.risk_rules.filter((r) => !r.passed).length }} failed / {{ node.risk_rules.length }} total
                  </p>
                  <p v-if="node.errors.length" class="mt-1 text-danger">{{ node.errors.length }} error(s)</p>
                </div>
                <div v-if="decisions.traces[decision.id]!.order_result" class="rounded-control bg-surface p-2 text-xs">
                  <p class="font-medium text-ink-950">Order result</p>
                  <p class="text-slate-600">Status: {{ decisions.traces[decision.id]!.order_result!.status }} · Broker ID: {{ decisions.traces[decision.id]!.order_result!.broker_order_id }}</p>
                </div>
                <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline" @click="exportJson(decision.id)">
                  <Download :size="12" />
                  Export sanitized JSON
                </button>
              </template>
            </div>
          </li>
        </ol>
      </div>
    </div>
  </div>
</template>
