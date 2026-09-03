<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { AlertTriangle, CheckCircle2, History, Loader2, Power, Settings2, ShieldCheck, X } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import GaugeChart from '@/components/charts/GaugeChart.vue'
import { useRiskStore } from '@/stores/risk'
import { useTradeProposalsStore } from '@/stores/tradeProposals'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatCurrency, formatDateTime, formatPercent } from '@/utils/format'
import type { RiskPolicyLimits, RiskRuleResult } from '@/types/api'

const risk = useRiskStore()
const proposals = useTradeProposalsStore()
const ui = useUiNotificationsStore()

onMounted(() => {
  void risk.loadAll()
  void proposals.loadProposals()
})

const killSwitchDialogOpen = ref(false)
const killSwitchEngaged = computed(() => risk.overview?.kill_switch_engaged ?? false)
async function doKillSwitch(): Promise<void> {
  await risk.toggleKillSwitch(!killSwitchEngaged.value)
  killSwitchDialogOpen.value = false
}

function acknowledgeAlert(id: string): void {
  const alert = risk.alerts.find((a) => a.id === id)
  if (alert) alert.acknowledged_at = new Date().toISOString()
}

interface RuleRow extends RiskRuleResult {
  symbol: string
}

const ruleRows = computed<RuleRow[]>(() =>
  proposals.proposals.flatMap((p) => (p.risk_evaluation?.rule_results ?? []).map((r) => ({ ...r, symbol: p.strategy.symbol }))),
)

const rejectedProposals = computed(() => proposals.proposals.filter((p) => p.status === 'rejected'))
const pendingProposals = computed(() => proposals.proposals.filter((p) => p.status === 'pending'))

// tradeProposals' isSubmitting is a single global flag shared by every action on that store --
// too coarse for a list where multiple rows could be actioned. Track per-row so approving one
// signal doesn't disable the buttons on every other pending row too.
const processingIds = ref<Set<string>>(new Set())
function isProcessing(id: string): boolean {
  return processingIds.value.has(id)
}
function setProcessing(id: string, on: boolean): void {
  const next = new Set(processingIds.value)
  if (on) next.add(id)
  else next.delete(id)
  processingIds.value = next
}

async function approveProposal(id: string): Promise<void> {
  if (isProcessing(id)) return
  setProcessing(id, true)
  try {
    await proposals.approve(id)
  } finally {
    setProcessing(id, false)
  }
}

const rejectDialogProposalId = ref<string | null>(null)
const rejectReason = ref('')
function requestReject(id: string): void {
  rejectDialogProposalId.value = id
  rejectReason.value = ''
}
async function confirmReject(): Promise<void> {
  const id = rejectDialogProposalId.value
  if (!id) return
  if (!rejectReason.value.trim()) {
    ui.error('Enter a reason so the AI can learn from this rejection.')
    return
  }
  setProcessing(id, true)
  try {
    const ok = await proposals.reject(id, rejectReason.value.trim())
    if (ok) rejectDialogProposalId.value = null
  } finally {
    setProcessing(id, false)
  }
}

// --- Policy edit drawer -----------------------------------------------------
const drawerOpen = ref(false)
const historyOpen = ref(false)
const formLimits = reactive<RiskPolicyLimits>({
  max_daily_loss_pct: 3.0,
  max_drawdown_pct: 10.0,
  max_position_risk_pct: 2.0,
  max_total_exposure_pct: 50.0,
  max_symbol_exposure_pct: 15.0,
  min_confidence: 0.65,
  max_quote_age_seconds: 60,
  min_open_interest: 100,
  max_spread_pct: 10.0,
  max_open_positions: 10,
})
const formErrors = ref<string[]>([])

function openDrawer(): void {
  if (risk.policy) Object.assign(formLimits, risk.policy.limits)
  formErrors.value = []
  drawerOpen.value = true
}

function validate(): boolean {
  const errors: string[] = []
  if (formLimits.min_confidence < 0 || formLimits.min_confidence > 1) errors.push('Minimum confidence must be between 0 and 1.')
  if (formLimits.max_position_risk_pct <= 0) errors.push('Max position risk must be greater than 0.')
  if (formLimits.max_symbol_exposure_pct > formLimits.max_total_exposure_pct) {
    errors.push('Per-symbol exposure limit cannot exceed total exposure limit.')
  }
  if (formLimits.max_open_positions < 1) errors.push('Max open positions must be at least 1.')
  formErrors.value = errors
  return errors.length === 0
}

async function savePolicy(): Promise<void> {
  if (!validate()) return
  const ok = await risk.savePolicy({ ...formLimits })
  if (ok) drawerOpen.value = false
}

const LIMIT_LABELS: Record<keyof RiskPolicyLimits, string> = {
  max_daily_loss_pct: 'Max daily loss (% equity)',
  max_drawdown_pct: 'Max drawdown (% equity)',
  max_position_risk_pct: 'Max position risk (% equity)',
  max_total_exposure_pct: 'Max total options exposure (% equity)',
  max_symbol_exposure_pct: 'Max single-symbol exposure (% equity)',
  min_confidence: 'Minimum model confidence',
  max_quote_age_seconds: 'Max quote age (seconds)',
  min_open_interest: 'Min open interest per leg',
  max_spread_pct: 'Max bid/ask spread (%)',
  max_open_positions: 'Max open positions',
}
const limitKeys = Object.keys(LIMIT_LABELS) as (keyof RiskPolicyLimits)[]
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['risk'] ?? null" />
    <ErrorRetry v-if="risk.error && !risk.overview" :message="risk.error" @retry="risk.loadAll" />

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-surface p-4 shadow-sm">
      <div class="flex items-center gap-2">
        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-success-50 text-success">
          <ShieldCheck :size="18" />
        </span>
        <div>
          <p class="text-sm font-semibold text-ink-950">AI Protection: {{ killSwitchEngaged ? 'HALTED' : 'ACTIVE' }}</p>
          <p class="text-xs text-slate-600">Every proposal is evaluated against risk policy v{{ risk.policy?.version ?? '--' }} before it can reach execution.</p>
        </div>
      </div>
      <div class="flex gap-2">
        <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas" @click="historyOpen = true">
          <History :size="14" />
          Version History
        </button>
        <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas" @click="openDrawer">
          <Settings2 :size="14" />
          Edit Policy
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-control px-3 py-1.5 text-sm font-semibold"
          :class="killSwitchEngaged ? 'bg-danger text-white hover:bg-danger/90' : 'border border-danger/30 bg-danger-50 text-danger hover:bg-danger/10'"
          @click="killSwitchDialogOpen = true"
        >
          <Power :size="14" />
          {{ killSwitchEngaged ? 'Disengage' : 'Kill Switch' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm lg:col-span-1">
        <h2 class="mb-1 text-sm font-semibold text-ink-950">Risk Score</h2>
        <Skeleton v-if="risk.isLoading && !risk.overview" height="200px" />
        <GaugeChart v-else-if="risk.overview" :value="risk.overview.risk_score" :label="risk.overview.risk_level" />
        <p class="text-center text-xs text-slate-600">Current level: <span class="font-semibold text-ink-950">{{ risk.overview?.risk_level }}</span></p>
      </div>

      <div class="grid grid-cols-2 gap-3 lg:col-span-2">
        <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
          <p class="text-xs text-slate-600">Daily P&amp;L / limit</p>
          <p class="tabular-nums mt-1 font-semibold text-ink-950">{{ formatCurrency(risk.overview?.daily_pl, { signed: true }) }} / {{ formatCurrency(risk.overview?.daily_loss_limit) }}</p>
        </div>
        <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
          <p class="text-xs text-slate-600">Exposure</p>
          <p class="tabular-nums mt-1 font-semibold text-ink-950">{{ formatPercent(risk.overview?.exposure_pct, { alreadyPercent: true }) }} / {{ formatPercent(risk.overview?.max_exposure_pct, { alreadyPercent: true }) }}</p>
        </div>
        <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
          <p class="text-xs text-slate-600">Position sizing budget</p>
          <p class="tabular-nums mt-1 font-semibold text-ink-950">{{ formatCurrency(risk.overview?.position_sizing_budget) }}</p>
        </div>
        <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
          <p class="text-xs text-slate-600">Drawdown</p>
          <p class="tabular-nums mt-1 font-semibold text-ink-950">{{ formatPercent(risk.overview?.drawdown_pct, { alreadyPercent: true }) }} / {{ formatPercent(risk.overview?.max_drawdown_pct, { alreadyPercent: true }) }}</p>
        </div>
        <div class="col-span-2 rounded-card border border-border bg-surface p-3.5 shadow-sm">
          <p class="text-xs text-slate-600">Remaining capacity</p>
          <p class="tabular-nums mt-1 font-semibold text-success">{{ formatCurrency(risk.overview?.remaining_capacity) }}</p>
        </div>
      </div>
    </div>

    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <h2 class="mb-3 text-sm font-semibold text-ink-950">Pending Approvals</h2>
      <div v-if="proposals.isLoading && proposals.proposals.length === 0" class="space-y-2">
        <Skeleton v-for="i in 2" :key="i" height="4.5rem" />
      </div>
      <EmptyState v-else-if="pendingProposals.length === 0" title="No pending signals" description="Every AI trade proposal has been actioned." :icon="CheckCircle2" />
      <ul v-else class="space-y-2">
        <li v-for="p in pendingProposals" :key="p.id" class="rounded-control border border-border p-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <p class="text-sm font-semibold text-ink-950">{{ p.strategy.symbol }}</p>
                <Badge tone="warning" class="capitalize">{{ p.strategy.strategy_type?.replaceAll('_', ' ') ?? '--' }}</Badge>
              </div>
              <p class="mt-1 line-clamp-2 text-xs text-slate-600">{{ p.strategy.thesis }}</p>
              <p class="mt-1 text-[11px] text-slate-600">Expires {{ formatDateTime(p.expires_at) }}</p>
            </div>
            <div class="flex shrink-0 gap-2">
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-control bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="isProcessing(p.id)"
                @click="approveProposal(p.id)"
              >
                <Loader2 v-if="isProcessing(p.id)" :size="13" class="animate-spin" />
                Approve Trade
              </button>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-control border border-danger/30 bg-danger-50 px-3 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/10 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="isProcessing(p.id)"
                @click="requestReject(p.id)"
              >
                Reject
              </button>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <h2 class="mb-3 text-sm font-semibold text-ink-950">Risk Policy — Recent Evaluations</h2>
      <div v-if="proposals.isLoading && ruleRows.length === 0" class="space-y-2">
        <Skeleton v-for="i in 4" :key="i" height="2rem" />
      </div>
      <EmptyState v-else-if="ruleRows.length === 0" title="No risk evaluations yet" />
      <div v-else class="scroll-x">
        <table class="w-full min-w-[720px] text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs text-slate-600">
              <th class="py-2 font-medium">Symbol</th>
              <th class="py-2 font-medium">Rule</th>
              <th class="py-2 font-medium">Observed</th>
              <th class="py-2 font-medium">Limit</th>
              <th class="py-2 font-medium">Status</th>
              <th class="py-2 font-medium">Severity</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in ruleRows" :key="`${row.symbol}-${row.id}`" class="border-b border-border last:border-0">
              <td class="py-2 font-medium text-ink-950">{{ row.symbol }}</td>
              <td class="py-2 text-slate-600" :title="row.explanation">{{ row.description }}</td>
              <td class="tabular-nums py-2">{{ row.observed_value }}</td>
              <td class="tabular-nums py-2">{{ row.limit_value }}</td>
              <td class="py-2">
                <Badge :tone="row.passed ? 'success' : 'danger'">{{ row.passed ? 'pass' : 'fail' }}</Badge>
              </td>
              <td class="py-2"><Badge :tone="row.severity === 'critical' ? 'danger' : row.severity === 'warning' ? 'warning' : 'neutral'">{{ row.severity }}</Badge></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-ink-950">Alerts</h2>
        <EmptyState v-if="risk.alerts.length === 0" title="No alerts" :icon="CheckCircle2" />
        <ul v-else class="space-y-2">
          <li v-for="alert in risk.alerts" :key="alert.id" class="rounded-control border border-border p-2.5">
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-start gap-2">
                <AlertTriangle :size="14" class="mt-0.5 shrink-0" :class="alert.severity === 'critical' ? 'text-danger' : alert.severity === 'warning' ? 'text-warning' : 'text-slate-600'" />
                <div>
                  <p class="text-sm font-medium text-ink-950">{{ alert.title }}</p>
                  <p class="mt-0.5 text-xs text-slate-600">{{ alert.message }}</p>
                  <p class="mt-1 text-[11px] text-slate-600">{{ formatDateTime(alert.created_at) }}</p>
                </div>
              </div>
              <button
                v-if="!alert.acknowledged_at"
                type="button"
                class="shrink-0 rounded-control border border-border px-2 py-1 text-xs font-medium text-ink-950 hover:bg-canvas"
                @click="acknowledgeAlert(alert.id)"
              >
                Acknowledge
              </button>
              <Badge v-else tone="neutral">acknowledged</Badge>
            </div>
          </li>
        </ul>
      </div>

      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-ink-950">Rejected Proposals</h2>
        <EmptyState v-if="rejectedProposals.length === 0" title="Nothing rejected" description="No proposals have been blocked by Risk Guardian." />
        <ul v-else class="space-y-2">
          <li v-for="p in rejectedProposals" :key="p.id" class="rounded-control border border-danger/20 bg-danger-50 p-2.5">
            <p class="text-sm font-medium text-ink-950">{{ p.strategy?.symbol ?? '--' }} — {{ p.strategy?.strategy_type?.replaceAll('_', ' ') ?? '--' }}</p>
            <p class="mt-1 text-xs text-danger">{{ p.rejected_reason }}</p>
          </li>
        </ul>
      </div>
    </div>

    <!-- Kill switch confirm -->
    <ConfirmDialog
      :open="killSwitchDialogOpen"
      :title="killSwitchEngaged ? 'Disengage kill switch?' : 'Engage kill switch?'"
      description="This is a paper-trading safety control and affects all agent trading activity immediately."
      :confirm-label="killSwitchEngaged ? 'Disengage' : 'Engage kill switch'"
      danger
      @confirm="doKillSwitch"
      @cancel="killSwitchDialogOpen = false"
    />

    <!-- Reject proposal reason -->
    <ConfirmDialog
      :open="!!rejectDialogProposalId"
      title="Reject trade proposal?"
      description="This feeds your reason back to the AI so future proposals can account for it."
      confirm-label="Reject proposal"
      danger
      :loading="rejectDialogProposalId ? isProcessing(rejectDialogProposalId) : false"
      @confirm="confirmReject"
      @cancel="rejectDialogProposalId = null"
    >
      <label for="reject-reason" class="mb-1 block text-xs font-medium text-slate-600">Reason (sent to the AI)</label>
      <textarea
        id="reject-reason"
        v-model="rejectReason"
        rows="3"
        class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600"
        placeholder="e.g. Too much correlated exposure to tech already."
      />
    </ConfirmDialog>

    <!-- Version history drawer -->
    <Teleport to="body">
      <div v-if="historyOpen" class="fixed inset-0 z-50 flex justify-end bg-ink-950/30" @click.self="historyOpen = false">
        <div class="h-full w-full max-w-sm overflow-y-auto bg-surface p-4 shadow-lg">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink-950">Policy Version History</h2>
            <button type="button" class="rounded p-1 text-slate-600 hover:bg-canvas" aria-label="Close" @click="historyOpen = false"><X :size="16" /></button>
          </div>
          <ul class="space-y-2">
            <li v-for="v in risk.policyHistory" :key="v.id" class="rounded-control border border-border p-2.5 text-sm">
              <div class="flex items-center justify-between">
                <p class="font-medium text-ink-950">Version {{ v.version }}</p>
                <Badge :tone="v.active ? 'success' : 'neutral'">{{ v.active ? 'active' : 'superseded' }}</Badge>
              </div>
              <p class="mt-1 text-xs text-slate-600">{{ formatDateTime(v.created_at) }}</p>
            </li>
          </ul>
        </div>
      </div>
    </Teleport>

    <!-- Edit policy drawer -->
    <Teleport to="body">
      <div v-if="drawerOpen" class="fixed inset-0 z-50 flex justify-end bg-ink-950/30" @click.self="drawerOpen = false">
        <div class="h-full w-full max-w-md overflow-y-auto bg-surface p-4 shadow-lg">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink-950">Edit Risk Policy</h2>
            <button type="button" class="rounded p-1 text-slate-600 hover:bg-canvas" aria-label="Close" @click="drawerOpen = false"><X :size="16" /></button>
          </div>
          <p class="mb-3 text-xs text-slate-600">Saving creates a new policy version — the previous version is preserved in history.</p>
          <div v-if="formErrors.length" class="mb-3 space-y-1 rounded-control border border-danger/20 bg-danger-50 p-2.5 text-xs text-danger">
            <p v-for="err in formErrors" :key="err">{{ err }}</p>
          </div>
          <form class="space-y-3" @submit.prevent="savePolicy">
            <div v-for="key in limitKeys" :key="key">
              <label :for="key" class="mb-1 block text-xs font-medium text-slate-600">{{ LIMIT_LABELS[key] }}</label>
              <input
                :id="key"
                v-model.number="formLimits[key]"
                type="number"
                step="any"
                class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600"
              />
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" class="rounded-control border border-border px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas" @click="drawerOpen = false">Cancel</button>
              <button type="submit" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="risk.isSavingPolicy">
                {{ risk.isSavingPolicy ? 'Saving…' : 'Save new version' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>
