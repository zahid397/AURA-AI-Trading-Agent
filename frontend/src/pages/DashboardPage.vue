<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Activity, ArrowUpRight, Bot, CheckCircle2, ChevronDown, DollarSign, Loader2, PiggyBank, ShieldAlert, Wallet } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import KpiCard from '@/components/common/KpiCard.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import EquityCurveChart from '@/components/charts/EquityCurveChart.vue'
import PayoffChart from '@/components/charts/PayoffChart.vue'
import { useAgentsStore } from '@/stores/agents'
import { useDashboardStore } from '@/stores/dashboard'
import { usePortfolioStore } from '@/stores/portfolio'
import { useStrategiesStore } from '@/stores/strategies'
import { useTradeProposalsStore } from '@/stores/tradeProposals'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import {
  formatCurrency,
  formatDateTime,
  formatPercent,
  formatRelativeTime,
  signClass,
  signDirection,
} from '@/utils/format'
import type { EquityCurveRange, ProposalStatus } from '@/types/api'

const dashboard = useDashboardStore()
const agents = useAgentsStore()
const portfolio = usePortfolioStore()
const strategies = useStrategiesStore()
const tradeProposals = useTradeProposalsStore()
const ui = useUiNotificationsStore()
const router = useRouter()

onMounted(() => {
  void dashboard.loadAll()
  void agents.loadAgents()
  void portfolio.loadAll()
})

// The dashboard's summary only carries the decision's status as of the last
// full page load. Once the user approves/executes here, we track the new
// status locally so the button/badge update immediately without waiting on
// a full dashboard refetch.
const isActingOnDecision = ref(false)
const localDecisionStatus = ref<ProposalStatus | 'analyzed' | null>(null)
const isDecisionDetailsOpen = ref(false)

watch(
  () => dashboard.summary?.latest_decision?.decision_id,
  () => {
    localDecisionStatus.value = null
    isDecisionDetailsOpen.value = false
  },
)

const decisionStatus = computed(() => localDecisionStatus.value ?? dashboard.summary?.latest_decision?.status ?? null)

const decisionBadgeTone = computed((): 'success' | 'warning' | 'danger' | 'info' | 'neutral' => {
  if (decisionStatus.value === 'executed' || decisionStatus.value === 'approved') return 'success'
  if (decisionStatus.value === 'pending') return 'warning'
  if (decisionStatus.value === 'rejected' || decisionStatus.value === 'expired') return 'danger'
  return 'neutral'
})

const decisionActionLabel = computed(() => {
  if (decisionStatus.value === 'pending') return 'Approve'
  if (decisionStatus.value === 'approved') return 'Execute Now'
  return null
})

async function actOnDecision(): Promise<void> {
  const decision = dashboard.summary?.latest_decision
  if (!decision?.trade_proposal_id || isActingOnDecision.value) return
  isActingOnDecision.value = true
  try {
    if (decisionStatus.value === 'pending') {
      const ok = await tradeProposals.approve(decision.trade_proposal_id)
      if (ok) localDecisionStatus.value = 'approved'
    } else if (decisionStatus.value === 'approved') {
      const ok = await tradeProposals.execute(decision.trade_proposal_id)
      if (ok) localDecisionStatus.value = 'executed'
    }
  } finally {
    isActingOnDecision.value = false
  }
}

async function toggleDecisionDetails(): Promise<void> {
  const decision = dashboard.summary?.latest_decision
  isDecisionDetailsOpen.value = !isDecisionDetailsOpen.value
  if (isDecisionDetailsOpen.value && decision && strategies.detail?.id !== decision.decision_id) {
    await strategies.selectStrategy(decision.decision_id)
  }
}

function onRangeChange(range: EquityCurveRange): void {
  void dashboard.loadEquityCurve(range)
}

const riskBadgeTone = computed(() => {
  const level = dashboard.summary?.risk_level
  if (level === 'LOW') return 'success'
  if (level === 'MODERATE') return 'info'
  if (level === 'ELEVATED') return 'warning'
  return 'danger'
})

const agentStatusTone = (status: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' => {
  if (status === 'running') return 'info'
  if (status === 'error') return 'danger'
  if (status === 'paused') return 'warning'
  return 'neutral'
}

function retryAll(): void {
  void dashboard.loadAll()
}

const degradedReason = computed(
  () => ui.fallbackDomains['dashboard-summary'] ?? ui.fallbackDomains['dashboard-equity-curve'] ?? ui.fallbackDomains['dashboard-activity'] ?? null,
)
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="degradedReason" />

    <!-- KPI row -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      <KpiCard
        label="Account Balance"
        :value="formatCurrency(dashboard.summary?.balance)"
        :loading="dashboard.isLoadingSummary && !dashboard.summary"
        :icon="Wallet"
        hint="Total account equity"
      />
      <KpiCard
        label="Total P&L"
        :value="formatCurrency(dashboard.summary?.total_pl, { signed: true })"
        :direction="signDirection(dashboard.summary?.total_pl)"
        :delta="dashboard.summary ? formatPercent(Number(dashboard.summary.total_pl) / Number(dashboard.summary.starting_equity), { signed: true, digits: 2 }) : undefined"
        :loading="dashboard.isLoadingSummary && !dashboard.summary"
        :icon="DollarSign"
      />
      <KpiCard
        label="Unrealized P&L"
        :value="formatCurrency(dashboard.summary?.unrealized_pl, { signed: true })"
        :direction="signDirection(dashboard.summary?.unrealized_pl)"
        :loading="dashboard.isLoadingSummary && !dashboard.summary"
        :icon="ArrowUpRight"
        hint="Open positions"
      />
      <KpiCard
        label="Buying Power"
        :value="formatCurrency(dashboard.summary?.buying_power)"
        :loading="dashboard.isLoadingSummary && !dashboard.summary"
        :icon="PiggyBank"
        hint="Available for new trades"
      />
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
          <p class="text-xs font-medium uppercase tracking-wide text-slate-600">Risk Level</p>
          <ShieldAlert :size="16" class="text-slate-600" aria-hidden="true" />
        </div>
        <template v-if="dashboard.isLoadingSummary && !dashboard.summary">
          <Skeleton height="1.75rem" width="60%" class="mt-2" />
        </template>
        <template v-else>
          <div class="mt-2">
            <Badge :tone="riskBadgeTone" class="text-sm">{{ dashboard.summary?.risk_level ?? '--' }}</Badge>
          </div>
          <router-link to="/risk-management" class="mt-1 inline-block text-xs font-medium text-brand-700 hover:underline">View risk details</router-link>
        </template>
      </div>
    </div>

    <ErrorRetry v-if="dashboard.summaryError" :message="dashboard.summaryError" @retry="retryAll" />

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
      <!-- Equity curve + latest decision -->
      <div class="space-y-5 xl:col-span-2">
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-1 text-sm font-semibold text-ink-950">Equity Curve</h2>
          <EquityCurveChart :points="dashboard.equityCurve" :range="dashboard.equityRange" :loading="dashboard.isLoadingCurve && dashboard.equityCurve.length === 0" @update:range="onRangeChange" />
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold text-ink-950">AI Agents Status</h2>
          <div v-if="agents.isLoading && agents.agents.length === 0" class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <Skeleton v-for="i in 5" :key="i" height="4.5rem" rounded="0.75rem" />
          </div>
          <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <router-link
              v-for="agent in agents.agents"
              :key="agent.id"
              to="/agents"
              class="rounded-control border border-border p-2.5 transition-colors hover:border-brand-300 hover:bg-brand-50"
            >
              <div class="flex items-center gap-1.5">
                <Bot :size="14" class="text-slate-600" />
                <p class="truncate text-xs font-medium text-ink-950">{{ agent.name }}</p>
              </div>
              <Badge :tone="agentStatusTone(agent.status)" class="mt-1.5">{{ agent.status }}</Badge>
              <p class="mt-1 text-[11px] text-slate-600">{{ agent.decisions_today }} decisions today</p>
            </router-link>
          </div>
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink-950">Open Positions</h2>
            <router-link to="/portfolio" class="text-xs font-medium text-brand-700 hover:underline">View portfolio</router-link>
          </div>
          <div v-if="portfolio.isLoading && portfolio.openPositions.length === 0" class="space-y-2">
            <Skeleton v-for="i in 3" :key="i" height="2.5rem" />
          </div>
          <EmptyState v-else-if="portfolio.openPositions.length === 0" title="No open positions" description="AURA hasn't opened any paper positions yet." />
          <div v-else class="scroll-x">
            <table class="w-full min-w-[560px] text-sm">
              <thead>
                <tr class="border-b border-border text-left text-xs text-slate-600">
                  <th class="py-2 font-medium">Symbol</th>
                  <th class="py-2 font-medium">Strategy</th>
                  <th class="py-2 font-medium">Qty</th>
                  <th class="py-2 font-medium">Value</th>
                  <th class="py-2 font-medium">Unrealized P&L</th>
                  <th class="py-2 font-medium">DTE</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="pos in portfolio.openPositions" :key="pos.id" class="border-b border-border last:border-0">
                  <td class="py-2 font-medium text-ink-950">{{ pos.symbol }}</td>
                  <td class="py-2 capitalize text-slate-600">{{ pos.strategy_type?.replaceAll('_', ' ') ?? '--' }}</td>
                  <td class="tabular-nums py-2">{{ pos.quantity }}</td>
                  <td class="tabular-nums py-2">{{ formatCurrency(pos.current_value) }}</td>
                  <td class="tabular-nums py-2 font-medium" :class="signClass(pos.unrealized_pl)">{{ formatCurrency(pos.unrealized_pl, { signed: true }) }}</td>
                  <td class="tabular-nums py-2">{{ pos.dte ?? '--' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold text-ink-950">Recent Trades</h2>
          <div v-if="dashboard.isLoadingExtras && dashboard.recentTrades.length === 0" class="space-y-2">
            <Skeleton v-for="i in 3" :key="i" height="2rem" />
          </div>
          <EmptyState v-else-if="dashboard.recentTrades.length === 0" title="No trades yet" description="Executed fills will appear here." />
          <ul v-else class="divide-y divide-border">
            <li v-for="trade in dashboard.recentTrades" :key="trade.id" class="flex items-center justify-between py-2 text-sm">
              <div>
                <p class="font-medium capitalize text-ink-950">{{ trade.side }} {{ trade.qty }}</p>
                <p class="text-xs text-slate-600">{{ formatDateTime(trade.filled_at) }}</p>
              </div>
              <p class="tabular-nums font-medium text-ink-950">{{ formatCurrency(trade.price) }}</p>
            </li>
          </ul>
        </div>
      </div>

      <!-- Right rail -->
      <div class="space-y-5">
        <div class="rounded-card border border-brand-100 bg-brand-50 p-4 shadow-sm">
          <div class="mb-2 flex items-center gap-1.5">
            <Activity :size="15" class="text-brand-700" />
            <h2 class="text-sm font-semibold text-brand-700">Latest AI Decision</h2>
          </div>
          <template v-if="dashboard.isLoadingSummary && !dashboard.summary">
            <Skeleton height="1rem" width="40%" />
            <Skeleton height="3rem" class="mt-2" />
          </template>
          <template v-else-if="dashboard.summary?.latest_decision">
            <div class="flex items-center justify-between">
              <p class="text-base font-semibold text-ink-950">{{ dashboard.summary.latest_decision.symbol }}</p>
              <Badge :tone="decisionBadgeTone" class="capitalize">{{ decisionStatus ?? '--' }}</Badge>
            </div>
            <p class="mt-1 text-xs capitalize text-slate-600">
              {{ dashboard.summary.latest_decision.strategy_type?.replaceAll('_', ' ') ?? '--' }} · {{ dashboard.summary.latest_decision.regime }} regime ·
              {{ formatPercent(dashboard.summary.latest_decision.confidence, { digits: 0 }) }} confidence
            </p>
            <p class="mt-2 text-sm text-slate-600" :class="isDecisionDetailsOpen ? '' : 'line-clamp-4'">{{ dashboard.summary.latest_decision.thesis }}</p>

            <div class="mt-3 flex flex-wrap items-center gap-2">
              <button
                v-if="decisionActionLabel"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-control bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="isActingOnDecision"
                @click="actOnDecision"
              >
                <Loader2 v-if="isActingOnDecision" :size="13" class="animate-spin" />
                {{ isActingOnDecision ? 'Working…' : decisionActionLabel }}
              </button>
              <span v-else-if="decisionStatus === 'executed'" class="inline-flex items-center gap-1 text-xs font-semibold text-success">
                <CheckCircle2 :size="14" /> Executed
              </span>

              <button
                type="button"
                class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink-950 transition-colors hover:bg-canvas"
                @click="toggleDecisionDetails"
              >
                View Details
                <ChevronDown :size="13" class="transition-transform" :class="isDecisionDetailsOpen ? 'rotate-180' : ''" />
              </button>

              <router-link :to="{ name: 'strategy-analysis' }" class="text-xs font-semibold text-brand-700 hover:underline">
                Full page →
              </router-link>
            </div>

            <div v-if="isDecisionDetailsOpen" class="mt-3 border-t border-border pt-3">
              <div v-if="strategies.isLoadingDetail && strategies.detail?.id !== dashboard.summary.latest_decision.decision_id" class="space-y-2">
                <Skeleton height="10rem" />
              </div>
              <ErrorRetry v-else-if="strategies.detailError" :message="strategies.detailError" @retry="toggleDecisionDetails" />
              <template v-else-if="strategies.detail && strategies.detail.id === dashboard.summary.latest_decision.decision_id">
                <PayoffChart v-if="strategies.payoff" :curve="strategies.payoff" />
                <dl class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                  <div>
                    <dt class="text-slate-600">Max profit</dt>
                    <dd class="tabular-nums font-semibold text-success">{{ formatCurrency(strategies.detail.max_profit) }}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-600">Max loss</dt>
                    <dd class="tabular-nums font-semibold text-danger">-{{ formatCurrency(strategies.detail.max_loss) }}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-600">Prob. of profit</dt>
                    <dd class="tabular-nums font-semibold text-ink-950">{{ formatPercent(strategies.detail.probability_of_profit) }}</dd>
                  </div>
                  <div>
                    <dt class="text-slate-600">AI score</dt>
                    <dd class="tabular-nums font-semibold text-ink-950">{{ formatPercent(strategies.detail.score_breakdown.total) }}</dd>
                  </div>
                </dl>
              </template>
            </div>
          </template>
          <EmptyState v-else title="No decisions yet" description="Run an analysis from the AI Agents page to generate a decision." />
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold text-ink-950">Market Overview</h2>
          <div v-if="dashboard.isLoadingExtras && dashboard.marketOverview.length === 0" class="space-y-2">
            <Skeleton v-for="i in 3" :key="i" height="2rem" />
          </div>
          <ul v-else class="space-y-2">
            <li v-for="item in dashboard.marketOverview" :key="item.symbol" class="flex items-center justify-between text-sm">
              <span class="font-medium text-ink-950">{{ item.symbol }}</span>
              <span class="text-xs capitalize text-slate-600">{{ item.trend }} · IV {{ item.iv_percentile }}th pct</span>
            </li>
          </ul>
          <button type="button" class="mt-3 text-xs font-medium text-brand-700 hover:underline" @click="router.push('/options-scanner')">
            Open full scanner →
          </button>
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold text-ink-950">Performance Summary</h2>
          <div v-if="portfolio.isLoading && !portfolio.performance" class="space-y-2">
            <Skeleton v-for="i in 4" :key="i" height="1.25rem" />
          </div>
          <dl v-else-if="portfolio.performance" class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt class="text-xs text-slate-600">Win rate</dt>
              <dd class="tabular-nums font-semibold text-ink-950">{{ formatPercent(portfolio.performance.win_rate) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-slate-600">Profit factor</dt>
              <dd class="tabular-nums font-semibold text-ink-950">{{ portfolio.performance.profit_factor }}</dd>
            </div>
            <div>
              <dt class="text-xs text-slate-600">Max drawdown</dt>
              <dd class="tabular-nums font-semibold text-danger">{{ formatPercent(portfolio.performance.max_drawdown, { alreadyPercent: true }) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-slate-600">Trades</dt>
              <dd class="tabular-nums font-semibold text-ink-950">{{ portfolio.performance.trade_count }}</dd>
            </div>
          </dl>
        </div>

        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold text-ink-950">AI Activity Timeline</h2>
          <div v-if="dashboard.isLoadingActivity && dashboard.activity.length === 0" class="space-y-3">
            <Skeleton v-for="i in 4" :key="i" height="2.5rem" />
          </div>
          <EmptyState v-else-if="dashboard.activity.length === 0" title="No recent activity" />
          <ol v-else class="max-h-80 space-y-3 overflow-y-auto">
            <li v-for="entry in dashboard.activity.slice(0, 10)" :key="entry.id" class="flex gap-2 text-sm">
              <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="entry.level === 'error' ? 'bg-danger' : entry.level === 'warn' ? 'bg-warning' : 'bg-brand-600'" />
              <div class="min-w-0">
                <p class="text-xs font-medium capitalize text-ink-950">{{ entry.agent_type?.replaceAll('_', ' ') ?? '--' }} · {{ formatRelativeTime(entry.created_at) }}</p>
                <p class="text-xs text-slate-600">{{ entry.message }}</p>
              </div>
            </li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</template>
