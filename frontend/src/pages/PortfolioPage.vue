<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronDown, Download, TrendingUp, X, XCircle } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import LineAreaChart from '@/components/charts/LineAreaChart.vue'
import MonthlyReturnHeatmap from '@/components/charts/MonthlyReturnHeatmap.vue'
import { CHART_COLORS } from '@/components/charts/echarts'
import { useDashboardStore } from '@/stores/dashboard'
import { usePortfolioStore } from '@/stores/portfolio'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatCurrency, formatDateTime, formatPercent, occSymbolLabel, signClass } from '@/utils/format'
import type { Position } from '@/types/api'

const portfolio = usePortfolioStore()
const dashboard = useDashboardStore()
const ui = useUiNotificationsStore()

type Tab = 'open' | 'closed' | 'orders' | 'activity'
const activeTab = ref<Tab>('open')
const selectedPosition = ref<Position | null>(null)

const expandedPnlIds = ref<Set<string>>(new Set())
function togglePnlCurve(id: string): void {
  const next = new Set(expandedPnlIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedPnlIds.value = next
}

/**
 * There's no per-tick P&L history endpoint for a position -- this interpolates a
 * deterministic (seeded by position id, so it's stable across re-renders) curve from
 * $0 at open to the position's current unrealized P&L, the same "illustrative, not a
 * real feed" approach already used for the benchmark series above.
 */
function pnlCurveFor(position: Position) {
  const opened = new Date(position.opened_at).getTime()
  const now = Math.max(Date.now(), opened + 1)
  const totalPl = Number.parseFloat(position.unrealized_pl)
  const steps = 20
  let seed = Array.from(position.id).reduce((acc, ch) => acc + ch.charCodeAt(0), 1)
  const rand = () => {
    seed = (seed * 9301 + 49297) % 233280
    return seed / 233280
  }
  const points = Array.from({ length: steps + 1 }, (_, i) => {
    const t = new Date(opened + ((now - opened) * i) / steps).toISOString()
    if (i === steps) return { t, value: totalPl }
    const noise = (rand() - 0.5) * Math.abs(totalPl || 1) * 0.3
    return { t, value: (totalPl * i) / steps + noise }
  })
  return [{ name: 'P&L (illustrative)', points, color: totalPl >= 0 ? CHART_COLORS.success : CHART_COLORS.danger, area: true }]
}

const closeDialogPosition = ref<Position | null>(null)
function requestClosePosition(pos: Position): void {
  closeDialogPosition.value = pos
}
function confirmClosePosition(): void {
  if (closeDialogPosition.value) portfolio.closePosition(closeDialogPosition.value.id)
  closeDialogPosition.value = null
}

onMounted(() => {
  void portfolio.loadAll()
  if (dashboard.equityCurve.length === 0) void dashboard.loadEquityCurve('3M')
})

const benchmarkSeries = computed(() =>
  dashboard.equityCurve.map((p, i, arr) => {
    const start = Number.parseFloat(arr[0]?.equity ?? '100000')
    const growth = 0.0004 * i // illustrative flat ~3%/yr drift, not a real index feed
    return { t: p.t, value: start * (1 + growth) }
  }),
)

const equitySeries = computed(() => [
  { name: 'Portfolio', points: dashboard.equityCurve.map((p) => ({ t: p.t, value: Number.parseFloat(p.equity) })), color: CHART_COLORS.brand, area: true },
  { name: 'Benchmark (illustrative)', points: benchmarkSeries.value, color: CHART_COLORS.slate },
])

function exportCsv(): void {
  const rows =
    activeTab.value === 'open'
      ? portfolio.openPositions
      : activeTab.value === 'closed'
        ? portfolio.closedPositions
        : []
  if (activeTab.value === 'orders') {
    const header = 'order_id,symbol,status,qty,submitted_via,submitted_at\n'
    const body = portfolio.orders.map((o) => [o.id, o.symbol, o.status, o.qty, o.submitted_via, o.submitted_at ?? ''].join(',')).join('\n')
    downloadCsv(header + body, 'orders.csv')
    return
  }
  if (activeTab.value === 'activity') {
    const header = 'trade_id,order_id,side,qty,price,filled_at\n'
    const body = portfolio.trades.map((t) => [t.id, t.order_id, t.side, t.qty, t.price, t.filled_at].join(',')).join('\n')
    downloadCsv(header + body, 'activity.csv')
    return
  }
  const header = 'symbol,strategy_type,quantity,avg_entry_price,current_value,unrealized_pl,status,dte\n'
  const body = rows.map((p) => [p.symbol, p.strategy_type ?? '', p.quantity, p.avg_entry_price, p.current_value, p.unrealized_pl, p.status, p.dte ?? ''].join(',')).join('\n')
  downloadCsv(header + body, `positions-${activeTab.value}.csv`)
}

function downloadCsv(content: string, filename: string): void {
  const blob = new Blob([content], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
  ui.success(`Exported ${filename}.`)
}
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['portfolio'] ?? null" />
    <ErrorRetry v-if="portfolio.error && !portfolio.summary" :message="portfolio.error" @retry="portfolio.loadAll" />

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
        <p class="text-xs text-slate-600">Equity</p>
        <p class="tabular-nums mt-1 text-lg font-semibold text-ink-950">{{ formatCurrency(portfolio.summary?.equity) }}</p>
      </div>
      <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
        <p class="text-xs text-slate-600">Unrealized / Realized P&amp;L</p>
        <p class="tabular-nums mt-1 text-sm font-semibold" :class="signClass(portfolio.summary?.unrealized_pl)">
          {{ formatCurrency(portfolio.summary?.unrealized_pl, { signed: true }) }} / {{ formatCurrency(portfolio.summary?.realized_pl, { signed: true }) }}
        </p>
      </div>
      <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
        <p class="text-xs text-slate-600">Buying Power</p>
        <p class="tabular-nums mt-1 text-lg font-semibold text-ink-950">{{ formatCurrency(portfolio.summary?.buying_power) }}</p>
      </div>
      <div class="rounded-card border border-border bg-surface p-3.5 shadow-sm">
        <p class="text-xs text-slate-600">Options Exposure</p>
        <p class="tabular-nums mt-1 text-lg font-semibold text-ink-950">{{ formatCurrency(portfolio.summary?.options_exposure) }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm xl:col-span-2">
        <h2 class="mb-2 text-sm font-semibold text-ink-950">Equity vs Benchmark</h2>
        <Skeleton v-if="dashboard.isLoadingCurve && dashboard.equityCurve.length === 0" height="260px" />
        <LineAreaChart v-else :series="equitySeries" show-legend :value-formatter="(v) => formatCurrency(v)" />
      </div>
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold text-ink-950">Allocation by Underlying</h2>
        <DonutChart v-if="portfolio.allocation" :slices="portfolio.allocation.by_underlying" />
        <Skeleton v-else height="240px" />
      </div>
    </div>

    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <h2 class="mb-3 text-sm font-semibold text-ink-950">Performance Analytics</h2>
      <div v-if="portfolio.performance" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div><p class="text-xs text-slate-600">Win rate</p><p class="tabular-nums font-semibold text-ink-950">{{ formatPercent(portfolio.performance.win_rate) }}</p></div>
        <div><p class="text-xs text-slate-600">Avg win / loss</p><p class="tabular-nums font-semibold text-ink-950">{{ formatCurrency(portfolio.performance.avg_win) }} / {{ formatCurrency(portfolio.performance.avg_loss) }}</p></div>
        <div><p class="text-xs text-slate-600">Profit factor</p><p class="tabular-nums font-semibold text-ink-950">{{ portfolio.performance.profit_factor }}</p></div>
        <div><p class="text-xs text-slate-600">Max drawdown</p><p class="tabular-nums font-semibold text-danger">{{ formatPercent(portfolio.performance.max_drawdown, { alreadyPercent: true }) }}</p></div>
      </div>
      <div class="mt-4">
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Monthly returns</h3>
        <MonthlyReturnHeatmap v-if="portfolio.performance" :data="portfolio.performance.monthly_returns" />
      </div>
    </div>

    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div role="tablist" class="flex gap-1 rounded-control border border-border bg-canvas p-0.5">
          <button v-for="tab in (['open', 'closed', 'orders', 'activity'] as Tab[])" :key="tab" type="button" role="tab" :aria-selected="activeTab === tab" class="rounded px-3 py-1 text-xs font-medium capitalize" :class="activeTab === tab ? 'bg-surface text-brand-700 shadow-sm' : 'text-slate-600'" @click="activeTab = tab">
            {{ tab }}
          </button>
        </div>
        <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-border px-2.5 py-1.5 text-xs font-medium text-ink-950 hover:bg-canvas" @click="exportCsv">
          <Download :size="13" />
          Export CSV
        </button>
      </div>

      <div v-if="portfolio.isLoading && portfolio.openPositions.length === 0" class="space-y-2">
        <Skeleton v-for="i in 3" :key="i" height="2.5rem" />
      </div>

      <template v-else-if="activeTab === 'open' || activeTab === 'closed'">
        <EmptyState v-if="(activeTab === 'open' ? portfolio.openPositions : portfolio.closedPositions).length === 0" title="No positions" />
        <div v-else class="scroll-x">
          <table class="w-full min-w-[720px] text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs text-slate-600">
                <th class="py-2 font-medium">Symbol</th>
                <th class="py-2 font-medium">Strategy</th>
                <th class="py-2 font-medium">Qty</th>
                <th class="py-2 font-medium">Entry</th>
                <th class="py-2 font-medium">Value</th>
                <th class="py-2 font-medium">P&amp;L</th>
                <th class="py-2 font-medium">DTE</th>
                <th class="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="pos in activeTab === 'open' ? portfolio.openPositions : portfolio.closedPositions" :key="pos.id">
                <tr class="cursor-pointer border-b border-border last:border-0 hover:bg-canvas" @click="selectedPosition = pos">
                  <td class="py-2 font-medium text-ink-950">{{ pos.symbol }}</td>
                  <td class="py-2 capitalize text-slate-600">{{ pos.strategy_type?.replaceAll('_', ' ') ?? '--' }}</td>
                  <td class="tabular-nums py-2">{{ pos.quantity }}</td>
                  <td class="tabular-nums py-2">{{ formatCurrency(pos.avg_entry_price) }}</td>
                  <td class="tabular-nums py-2">{{ formatCurrency(pos.current_value) }}</td>
                  <td class="tabular-nums py-2 font-medium" :class="signClass(pos.unrealized_pl)">{{ formatCurrency(pos.unrealized_pl, { signed: true }) }}</td>
                  <td class="tabular-nums py-2">{{ pos.dte ?? '--' }}</td>
                  <td class="py-2" @click.stop>
                    <div class="flex items-center gap-3">
                      <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline" @click="togglePnlCurve(pos.id)">
                        <TrendingUp :size="12" />
                        P&amp;L Curve
                        <ChevronDown :size="12" class="transition-transform" :class="expandedPnlIds.has(pos.id) ? 'rotate-180' : ''" />
                      </button>
                      <button
                        v-if="activeTab === 'open'"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-medium text-danger hover:underline"
                        @click="requestClosePosition(pos)"
                      >
                        <XCircle :size="12" />
                        Close Position
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandedPnlIds.has(pos.id)" class="border-b border-border bg-canvas last:border-0">
                  <td colspan="8" class="p-3">
                    <p class="mb-2 text-xs text-slate-600">
                      {{ pos.symbol }} P&amp;L since {{ formatDateTime(pos.opened_at) }}
                      <span class="text-slate-600/70">(illustrative interpolation, not tick-level history)</span>
                    </p>
                    <LineAreaChart :series="pnlCurveFor(pos)" height="160px" :value-formatter="(v) => formatCurrency(v, { signed: true })" />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="activeTab === 'orders'">
        <EmptyState v-if="portfolio.orders.length === 0" title="No orders" />
        <div v-else class="scroll-x">
          <table class="w-full min-w-[640px] text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs text-slate-600">
                <th class="py-2 font-medium">Symbol</th>
                <th class="py-2 font-medium">Status</th>
                <th class="py-2 font-medium">Qty</th>
                <th class="py-2 font-medium">Via</th>
                <th class="py-2 font-medium">Submitted</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in portfolio.orders" :key="order.id" class="border-b border-border last:border-0">
                <td class="py-2 font-medium text-ink-950">{{ order.symbol }}</td>
                <td class="py-2"><Badge :tone="order.status === 'filled' ? 'success' : order.status === 'rejected' ? 'danger' : 'info'" class="capitalize">{{ order.status }}</Badge></td>
                <td class="tabular-nums py-2">{{ order.qty }}</td>
                <td class="py-2 capitalize text-slate-600">{{ order.submitted_via?.replaceAll('_', ' ') ?? '--' }}</td>
                <td class="py-2 text-xs text-slate-600">{{ formatDateTime(order.submitted_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else>
        <EmptyState v-if="portfolio.trades.length === 0" title="No activity yet" />
        <ul v-else class="divide-y divide-border">
          <li v-for="trade in portfolio.trades" :key="trade.id" class="flex items-center justify-between py-2 text-sm">
            <div>
              <p class="font-medium capitalize text-ink-950">{{ trade.side }} {{ trade.qty }}</p>
              <p class="text-xs text-slate-600">{{ formatDateTime(trade.filled_at) }}</p>
            </div>
            <p class="tabular-nums font-medium text-ink-950">{{ formatCurrency(trade.price) }}</p>
          </li>
        </ul>
      </template>
    </div>

    <Teleport to="body">
      <div v-if="selectedPosition" class="fixed inset-0 z-50 flex justify-end bg-ink-950/30" @click.self="selectedPosition = null">
        <div class="h-full w-full max-w-md overflow-y-auto bg-surface p-4 shadow-lg">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-ink-950">{{ selectedPosition.symbol }} position</h2>
            <button type="button" class="rounded p-1 text-slate-600 hover:bg-canvas" aria-label="Close" @click="selectedPosition = null"><X :size="16" /></button>
          </div>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-xs text-slate-600">Strategy</dt><dd class="font-medium capitalize text-ink-950">{{ selectedPosition.strategy_type?.replaceAll('_', ' ') ?? '--' }}</dd></div>
            <div><dt class="text-xs text-slate-600">Quantity</dt><dd class="tabular-nums font-medium text-ink-950">{{ selectedPosition.quantity }}</dd></div>
            <div><dt class="text-xs text-slate-600">Avg entry</dt><dd class="tabular-nums font-medium text-ink-950">{{ formatCurrency(selectedPosition.avg_entry_price) }}</dd></div>
            <div><dt class="text-xs text-slate-600">Current value</dt><dd class="tabular-nums font-medium text-ink-950">{{ formatCurrency(selectedPosition.current_value) }}</dd></div>
            <div><dt class="text-xs text-slate-600">Unrealized P&amp;L</dt><dd class="tabular-nums font-medium" :class="signClass(selectedPosition.unrealized_pl)">{{ formatCurrency(selectedPosition.unrealized_pl, { signed: true }) }}</dd></div>
            <div><dt class="text-xs text-slate-600">Opened</dt><dd class="text-xs font-medium text-ink-950">{{ formatDateTime(selectedPosition.opened_at) }}</dd></div>
          </dl>
          <div v-if="selectedPosition.greeks" class="mt-3 rounded-control border border-border bg-canvas p-2.5">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">Greeks</p>
            <div class="grid grid-cols-4 gap-2 text-xs">
              <div><dt class="text-slate-600">Delta</dt><dd class="tabular-nums font-medium text-ink-950">{{ selectedPosition.greeks.delta }}</dd></div>
              <div><dt class="text-slate-600">Gamma</dt><dd class="tabular-nums font-medium text-ink-950">{{ selectedPosition.greeks.gamma }}</dd></div>
              <div><dt class="text-slate-600">Theta</dt><dd class="tabular-nums font-medium text-ink-950">{{ selectedPosition.greeks.theta }}</dd></div>
              <div><dt class="text-slate-600">Vega</dt><dd class="tabular-nums font-medium text-ink-950">{{ selectedPosition.greeks.vega }}</dd></div>
            </div>
          </div>
          <div v-if="selectedPosition.legs.length" class="mt-3">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">Legs</p>
            <ul class="space-y-1 text-xs text-slate-600">
              <li v-for="leg in selectedPosition.legs" :key="leg.id">{{ occSymbolLabel(leg.occ_symbol) }}</li>
            </ul>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="!!closeDialogPosition"
      title="Close position?"
      :description="closeDialogPosition ? `Are you sure you want to close the ${closeDialogPosition.symbol} position? This cannot be undone.` : ''"
      confirm-label="Close position"
      danger
      @confirm="confirmClosePosition"
      @cancel="closeDialogPosition = null"
    />
  </div>
</template>
