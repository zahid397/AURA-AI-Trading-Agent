<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  Activity,
  Brain,
  Check,
  ChevronDown,
  Clock3,
  GraduationCap,
  Loader2,
  PauseCircle,
  PlayCircle,
  Power,
  Radar,
  ShieldAlert,
  StopCircle,
  X,
  Zap,
} from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import { useAgentsStore } from '@/stores/agents'
import { useRiskStore } from '@/stores/risk'
import { useSettingsStore } from '@/stores/settings'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatDateTime, formatNumber, formatRelativeTime } from '@/utils/format'
import type { AgentType } from '@/types/api'

const agents = useAgentsStore()
const risk = useRiskStore()
const settings = useSettingsStore()
const ui = useUiNotificationsStore()

const AGENT_ICONS: Record<AgentType, typeof Radar> = {
  market_analyst: Radar,
  options_strategy: Brain,
  risk_guardian: ShieldAlert,
  execution: Zap,
  learning: GraduationCap,
}

const runSymbols = ref('SPY, NVDA, TSLA')
const killSwitchDialogOpen = ref(false)
const pauseAllDialogOpen = ref(false)

onMounted(() => {
  void agents.loadAgents()
  if (!risk.overview) void risk.loadAll()
  if (!settings.settings) void settings.loadAll()
})

const autonomyMode = computed(() => settings.settings?.agents?.autonomy_mode ?? 'approval_required')
const killSwitchEngaged = computed(() => risk.overview?.kill_switch_engaged ?? false)

function statusTone(status: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  if (status === 'running') return 'info'
  if (status === 'error') return 'danger'
  if (status === 'paused') return 'warning'
  return 'neutral'
}

async function runAnalysis(): Promise<void> {
  const symbols = runSymbols.value
    .split(',')
    .map((s) => s.trim().toUpperCase())
    .filter(Boolean)
  if (symbols.length === 0) {
    ui.error('Enter at least one symbol to analyze.')
    return
  }
  await agents.runAnalysis(symbols)
  await agents.loadAgents()
}

async function stopAnalysis(): Promise<void> {
  await agents.cancelRun()
}

function confirmPauseAll(): void {
  pauseAllDialogOpen.value = true
}
async function doPauseAll(): Promise<void> {
  await agents.pauseAll()
  pauseAllDialogOpen.value = false
}

function confirmKillSwitch(): void {
  killSwitchDialogOpen.value = true
}
async function doKillSwitch(): Promise<void> {
  await risk.toggleKillSwitch(!killSwitchEngaged.value)
  killSwitchDialogOpen.value = false
}

const WORKFLOW: { type: AgentType; label: string }[] = [
  { type: 'market_analyst', label: 'Market Analyst' },
  { type: 'options_strategy', label: 'Options Strategy' },
  { type: 'risk_guardian', label: 'Risk Guardian' },
  { type: 'execution', label: 'Execution' },
  { type: 'learning', label: 'Learning' },
]

function nodeStatus(type: AgentType): string {
  return agents.agents.find((a) => a.type === type)?.status ?? 'idle'
}

// The backend doesn't expose per-node timing for a run (only its overall status), so once a
// run is triggered we drive the 5 pipeline steps through idle -> running -> done ourselves,
// paced on a timer, and reconcile with the real run's terminal status as soon as it lands --
// this keeps the visualization responsive even in fixture mode where the run resolves instantly.
const PIPELINE_STEP_MS = 1100
const pipelineStageIndex = ref(0)
const pipelineOutcome = ref<'idle' | 'running' | 'done' | 'failed' | 'stopped'>('idle')
let pipelineTimer: ReturnType<typeof setInterval> | null = null

function stopPipelineTimer(): void {
  if (pipelineTimer !== null) {
    clearInterval(pipelineTimer)
    pipelineTimer = null
  }
}

watch(
  () => agents.isRunActive,
  (active) => {
    if (active) {
      pipelineStageIndex.value = 0
      pipelineOutcome.value = 'running'
      stopPipelineTimer()
      pipelineTimer = setInterval(() => {
        if (pipelineStageIndex.value < WORKFLOW.length - 1) pipelineStageIndex.value += 1
      }, PIPELINE_STEP_MS)
    } else if (pipelineOutcome.value === 'running') {
      stopPipelineTimer()
      const status = agents.activeRun?.status
      if (status === 'failed') {
        pipelineOutcome.value = 'failed'
      } else if (status === 'cancelled' || status === 'rejected') {
        pipelineOutcome.value = 'stopped'
      } else {
        pipelineStageIndex.value = WORKFLOW.length
        pipelineOutcome.value = 'done'
      }
    }
  },
)

onUnmounted(() => stopPipelineTimer())

type PipelineVisualStatus = 'idle' | 'running' | 'done' | 'error'

function pipelineNodeStatus(type: AgentType, index: number): PipelineVisualStatus {
  if (pipelineOutcome.value === 'idle') {
    return nodeStatus(type) === 'running' ? 'running' : 'idle'
  }
  if (pipelineOutcome.value === 'failed' && index === pipelineStageIndex.value) return 'error'
  if (index < pipelineStageIndex.value) return 'done'
  if (index === pipelineStageIndex.value && pipelineOutcome.value === 'running') return 'running'
  return 'idle'
}

async function onSelect(type: AgentType): Promise<void> {
  if (agents.selectedType === type) {
    agents.clearSelection()
    return
  }
  await agents.selectAgent(type)
}
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['agents-list'] ?? null" />

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-surface p-4 shadow-sm">
      <div>
        <div class="flex items-center gap-2">
          <h2 class="text-sm font-semibold text-ink-950">Autonomy mode</h2>
          <Badge tone="info" class="capitalize">{{ autonomyMode?.replaceAll('_', ' ') ?? '--' }}</Badge>
          <Badge v-if="killSwitchEngaged" tone="danger">Kill switch engaged</Badge>
        </div>
        <div class="mt-2 flex items-center gap-2">
          <input
            v-model="runSymbols"
            type="text"
            class="w-64 rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600"
            placeholder="Symbols, comma separated"
            aria-label="Symbols to analyze"
          />
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60"
          :disabled="agents.isRunActive || killSwitchEngaged"
          @click="runAnalysis"
        >
          <Loader2 v-if="agents.isRunActive" :size="15" class="animate-spin" />
          <PlayCircle v-else :size="15" />
          {{ agents.isRunActive ? 'Running…' : 'Run Analysis' }}
        </button>
        <button
          v-if="agents.isRunActive"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-control border border-warning/30 bg-warning-50 px-3 py-1.5 text-sm font-medium text-warning hover:bg-warning/10"
          @click="stopAnalysis"
        >
          <StopCircle :size="15" />
          Pause / Stop Analysis
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas"
          @click="confirmPauseAll"
        >
          <PauseCircle :size="15" />
          Pause All
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-sm font-semibold"
          :class="killSwitchEngaged ? 'bg-danger text-white hover:bg-danger/90' : 'border border-danger/30 bg-danger-50 text-danger hover:bg-danger/10'"
          @click="confirmKillSwitch"
        >
          <Power :size="15" />
          {{ killSwitchEngaged ? 'Disengage Kill Switch' : 'Kill Switch' }}
        </button>
      </div>
    </div>

    <!-- Workflow map -->
    <div class="rounded-card border border-border bg-surface p-5 shadow-sm">
      <h2 class="mb-4 text-sm font-semibold text-ink-950">Pipeline</h2>
      <div class="flex flex-col items-stretch gap-0 sm:flex-row sm:items-center">
        <template v-for="(node, i) in WORKFLOW" :key="node.type">
          <div
            class="flex flex-1 items-center gap-2 rounded-control border p-3"
            :class="{
              'border-brand-400 bg-brand-50': pipelineNodeStatus(node.type, i) === 'running',
              'border-success/40 bg-success-50': pipelineNodeStatus(node.type, i) === 'done',
              'border-danger/40 bg-danger-50': pipelineNodeStatus(node.type, i) === 'error',
              'border-border bg-canvas': pipelineNodeStatus(node.type, i) === 'idle',
            }"
          >
            <Loader2 v-if="pipelineNodeStatus(node.type, i) === 'running'" :size="16" class="shrink-0 animate-spin text-brand-700" />
            <Check v-else-if="pipelineNodeStatus(node.type, i) === 'done'" :size="16" class="shrink-0 text-success" />
            <X v-else-if="pipelineNodeStatus(node.type, i) === 'error'" :size="16" class="shrink-0 text-danger" />
            <component :is="AGENT_ICONS[node.type]" v-else :size="16" class="shrink-0 text-slate-600" />
            <div class="min-w-0">
              <p class="truncate text-xs font-medium text-ink-950">{{ node.label }}</p>
              <Badge
                :tone="pipelineNodeStatus(node.type, i) === 'error' ? 'danger' : pipelineNodeStatus(node.type, i) === 'done' ? 'success' : statusTone(nodeStatus(node.type))"
                class="mt-0.5"
              >
                {{ pipelineOutcome === 'idle' ? nodeStatus(node.type) : pipelineNodeStatus(node.type, i) }}
              </Badge>
            </div>
          </div>
          <div v-if="i < WORKFLOW.length - 1" class="flex h-6 items-center justify-center sm:h-auto sm:w-6" aria-hidden="true">
            <ChevronDown :size="16" class="text-slate-600 sm:-rotate-90" />
          </div>
        </template>
      </div>
    </div>

    <!-- Agent cards -->
    <div v-if="agents.isLoading && agents.agents.length === 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <Skeleton v-for="i in 5" :key="i" height="9rem" rounded="0.875rem" />
    </div>
    <ErrorRetry v-else-if="agents.error" :message="agents.error" @retry="agents.loadAgents" />
    <EmptyState v-else-if="agents.agents.length === 0" title="No agents configured" />
    <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <button
        v-for="agent in agents.agents"
        :key="agent.id"
        type="button"
        class="rounded-card border p-3.5 text-left shadow-sm transition-colors"
        :class="agents.selectedType === agent.type ? 'border-brand-400 bg-brand-50' : 'border-border bg-surface hover:border-brand-300'"
        @click="onSelect(agent.type)"
      >
        <div class="flex items-center gap-2">
          <component :is="AGENT_ICONS[agent.type]" :size="16" class="text-slate-600" />
          <p class="truncate text-sm font-semibold text-ink-950">{{ agent.name }}</p>
        </div>
        <Badge :tone="statusTone(agent.status)" class="mt-2">{{ agent.status }}</Badge>
        <p class="mt-2 line-clamp-2 text-xs text-slate-600">{{ agent.current_task ?? 'No current task.' }}</p>
        <dl class="mt-2 grid grid-cols-2 gap-1 text-[11px] text-slate-600">
          <div>
            <dt class="inline">Heartbeat: </dt>
            <dd class="inline tabular-nums">{{ formatRelativeTime(agent.last_heartbeat_at) }}</dd>
          </div>
          <div>
            <dt class="inline">Decisions: </dt>
            <dd class="inline tabular-nums">{{ agent.decisions_today }}</dd>
          </div>
          <div class="col-span-2">
            <dt class="inline">Avg latency: </dt>
            <dd class="inline tabular-nums">{{ formatNumber(agent.avg_latency_ms) }}ms</dd>
          </div>
        </dl>
      </button>
    </div>

    <!-- Detail panel -->
    <div v-if="agents.selectedType" class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <div v-if="agents.isLoadingDetail" class="space-y-2">
        <Skeleton height="1.5rem" width="40%" />
        <Skeleton height="4rem" />
      </div>
      <template v-else-if="agents.selectedDetail">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-ink-950">{{ agents.selectedDetail.name }} — logs &amp; detail</h2>
          <Badge tone="neutral">config v{{ agents.selectedDetail.config_version }}</Badge>
        </div>
        <p class="mb-3 text-sm text-slate-600">{{ agents.selectedDetail.objective }}</p>
        <div v-if="agents.selectedLogs.length === 0">
          <EmptyState title="No recent logs" :icon="Activity" />
        </div>
        <ol v-else class="max-h-72 space-y-2 overflow-y-auto">
          <li v-for="log in agents.selectedLogs" :key="log.id" class="flex items-start gap-2 rounded-control bg-canvas p-2 text-sm">
            <Clock3 :size="13" class="mt-0.5 shrink-0 text-slate-600" />
            <div class="min-w-0">
              <p class="text-xs text-slate-600">{{ formatDateTime(log.created_at) }}</p>
              <p class="text-sm text-ink-950">{{ log.message }}</p>
            </div>
            <Badge :tone="log.level === 'error' ? 'danger' : log.level === 'warn' ? 'warning' : 'neutral'" class="ml-auto shrink-0">{{ log.level }}</Badge>
          </li>
        </ol>
      </template>
    </div>

    <ConfirmDialog
      :open="pauseAllDialogOpen"
      title="Pause all agents?"
      description="All 5 agents will stop picking up new work until resumed individually."
      confirm-label="Pause all"
      @confirm="doPauseAll"
      @cancel="pauseAllDialogOpen = false"
    />
    <ConfirmDialog
      :open="killSwitchDialogOpen"
      :title="killSwitchEngaged ? 'Disengage kill switch?' : 'Engage kill switch?'"
      :description="
        killSwitchEngaged
          ? 'Agents will resume normal operation and may propose or execute new paper trades again.'
          : 'This immediately halts all agent activity and blocks new trade execution across the account.'
      "
      :confirm-label="killSwitchEngaged ? 'Disengage' : 'Engage kill switch'"
      danger
      @confirm="doKillSwitch"
      @cancel="killSwitchDialogOpen = false"
    />
  </div>
</template>
