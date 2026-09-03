<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue'
import { Loader2, PlayCircle, StopCircle } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import BarChart from '@/components/charts/BarChart.vue'
import { CHART_COLORS } from '@/components/charts/echarts'
import LineAreaChart from '@/components/charts/LineAreaChart.vue'
import MonthlyReturnHeatmap from '@/components/charts/MonthlyReturnHeatmap.vue'
import { useBacktestsStore } from '@/stores/backtests'
import { formatCurrency, formatDateTime, formatPercent } from '@/utils/format'
import type { BacktestConfig } from '@/types/api'

const backtests = useBacktestsStore()

onMounted(() => {
  void backtests.loadRuns()
})

const form = reactive<BacktestConfig>({
  strategy: 'bull_call_spread',
  symbols: ['SPY'],
  date_range: { from: new Date(new Date().setMonth(new Date().getMonth() - 6)).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) },
  starting_capital: '100000',
  dte: { min: 21, max: 45 },
  entry_rules: 'Enter on bullish regime confirmation with IV percentile below 50.',
  exit_rules: 'Exit at 50% of max profit or 7 DTE, whichever first.',
  confidence_threshold: 0.55,
  slippage_pct: 0.02,
  risk_policy_version: 1,
})
const symbolsInput = computed({
  get: () => form.symbols.join(', '),
  set: (v: string) => {
    form.symbols = v
      .split(',')
      .map((s) => s.trim().toUpperCase())
      .filter(Boolean)
  },
})

async function runBacktest(): Promise<void> {
  await backtests.start({ ...form })
}
async function cancelActive(): Promise<void> {
  if (backtests.active) await backtests.cancel(backtests.active.id)
}

const provenanceLabel: Record<string, string> = {
  historical: 'Backed by real historical market data.',
  simulated_fixture: 'Simulated fixture data — directional illustration only, not real historical prices.',
  insufficient_data: 'Insufficient historical data for a reliable result.',
}
</script>

<template>
  <div class="space-y-5">
    <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
      <h2 class="mb-3 text-sm font-semibold text-ink-950">Backtest Builder</h2>
      <form class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="runBacktest">
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Strategy</label>
          <select v-model="form.strategy" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm">
            <option value="bull_call_spread">Bull Call Spread</option>
            <option value="bear_put_spread">Bear Put Spread</option>
            <option value="iron_condor">Iron Condor</option>
            <option value="covered_call">Covered Call</option>
            <option value="cash_secured_put">Cash Secured Put</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Symbols</label>
          <input v-model="symbolsInput" type="text" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" placeholder="SPY, QQQ" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
          <input v-model="form.date_range.from" type="date" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
          <input v-model="form.date_range.to" type="date" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Starting capital</label>
          <input v-model="form.starting_capital" type="text" inputmode="decimal" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">DTE min</label>
            <input v-model.number="form.dte.min" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">DTE max</label>
            <input v-model.number="form.dte.max" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Confidence threshold</label>
          <input v-model.number="form.confidence_threshold" type="number" step="0.05" min="0" max="1" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Slippage %</label>
          <input v-model.number="form.slippage_pct" type="number" step="0.01" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="sm:col-span-2 lg:col-span-2">
          <label class="mb-1 block text-xs font-medium text-slate-600">Entry rules</label>
          <textarea v-model="form.entry_rules" rows="2" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="sm:col-span-2 lg:col-span-2">
          <label class="mb-1 block text-xs font-medium text-slate-600">Exit rules</label>
          <textarea v-model="form.exit_rules" rows="2" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="flex items-end gap-2 lg:col-span-4">
          <button type="submit" class="inline-flex items-center gap-1.5 rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="backtests.isStarting">
            <Loader2 v-if="backtests.isStarting" :size="14" class="animate-spin" />
            <PlayCircle v-else :size="14" />
            {{ backtests.isStarting ? 'Running…' : 'Run Backtest' }}
          </button>
          <button v-if="backtests.active && backtests.active.status === 'running'" type="button" class="inline-flex items-center gap-1.5 rounded-control border border-danger/30 bg-danger-50 px-3 py-1.5 text-sm font-medium text-danger hover:bg-danger/10" @click="cancelActive">
            <StopCircle :size="14" />
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div v-if="backtests.isStarting && !backtests.active" class="space-y-3">
      <Skeleton height="4rem" />
      <Skeleton height="16rem" />
    </div>

    <ErrorRetry v-else-if="backtests.error && !backtests.active" :message="backtests.error" @retry="backtests.loadRuns" />
    <EmptyState v-else-if="!backtests.active" title="No backtest run yet" description="Configure the builder above and run a backtest to see results." />

    <template v-else>
      <div class="flex flex-wrap items-center gap-2 rounded-card border border-border bg-surface p-3 shadow-sm">
        <Badge :tone="backtests.active.provenance === 'historical' ? 'success' : backtests.active.provenance === 'simulated_fixture' ? 'warning' : 'danger'" class="capitalize">
          {{ backtests.active.provenance?.replaceAll('_', ' ') ?? '--' }}
        </Badge>
        <p class="text-xs text-slate-600">{{ provenanceLabel[backtests.active.provenance] }}</p>
        <Badge
          :tone="backtests.active.status === 'failed' ? 'danger' : backtests.active.status === 'completed' ? 'success' : 'info'"
          :icon="backtests.active.status !== 'pending' && backtests.active.status !== 'running'"
          class="ml-auto capitalize"
        >
          <Loader2 v-if="backtests.active.status === 'pending' || backtests.active.status === 'running'" :size="11" class="animate-spin" />
          {{ backtests.active.status }}
        </Badge>
      </div>

      <div v-if="backtests.isPolling && !backtests.active.result" class="flex items-center gap-2 rounded-card border border-border bg-surface p-4 text-sm text-slate-600 shadow-sm">
        <Loader2 :size="14" class="animate-spin" />
        Running backtest — this usually takes a few seconds…
      </div>
      <ErrorRetry v-else-if="backtests.active.status === 'failed'" message="The backtest failed to complete. Try adjusting the configuration and running it again." @retry="runBacktest" />

      <div v-if="backtests.active.result" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Win rate</p><p class="tabular-nums font-semibold text-ink-950">{{ formatPercent(backtests.active.result.metrics.win_rate) }}</p></div>
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Profit factor</p><p class="tabular-nums font-semibold text-ink-950">{{ backtests.active.result.metrics.profit_factor }}</p></div>
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Total return</p><p class="tabular-nums font-semibold text-success">{{ formatPercent(backtests.active.result.metrics.total_return, { alreadyPercent: false }) }}</p></div>
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Max drawdown</p><p class="tabular-nums font-semibold text-danger">{{ formatPercent(backtests.active.result.metrics.max_drawdown, { alreadyPercent: false }) }}</p></div>
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Trades</p><p class="tabular-nums font-semibold text-ink-950">{{ backtests.active.result.metrics.trade_count }}</p></div>
        <div class="rounded-card border border-border bg-surface p-3 shadow-sm"><p class="text-xs text-slate-600">Sample period</p><p class="font-semibold text-ink-950">{{ backtests.active.result.metrics.sample_period }}</p></div>
      </div>

      <div v-if="backtests.active.result" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-2 text-sm font-semibold text-ink-950">Equity Curve</h2>
          <LineAreaChart :series="[{ name: 'Equity', points: backtests.active.result.equity_curve.map((p) => ({ t: p.t, value: Number.parseFloat(p.equity) })), color: CHART_COLORS.brand, area: true }]" :value-formatter="(v) => formatCurrency(v)" />
        </div>
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-2 text-sm font-semibold text-ink-950">Drawdown</h2>
          <LineAreaChart :series="[{ name: 'Drawdown %', points: backtests.active.result.drawdown_curve.map((p) => ({ t: p.t, value: -p.drawdown_pct })), color: CHART_COLORS.danger, area: true }]" :value-formatter="(v) => formatPercent(v, { alreadyPercent: true })" />
        </div>
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-2 text-sm font-semibold text-ink-950">Return Distribution</h2>
          <BarChart :categories="backtests.active.result.return_distribution.map((r) => r.bucket)" :values="backtests.active.result.return_distribution.map((r) => r.count)" color-by-value />
        </div>
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <h2 class="mb-2 text-sm font-semibold text-ink-950">Monthly Returns</h2>
          <MonthlyReturnHeatmap :data="backtests.active.result.monthly_returns" />
        </div>
      </div>

      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-ink-950">Trades</h2>
        <EmptyState v-if="backtests.active.trades.length === 0" title="No trades recorded" />
        <div v-else class="scroll-x">
          <table class="w-full min-w-[640px] text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs text-slate-600">
                <th class="py-2 font-medium">Symbol</th>
                <th class="py-2 font-medium">Entry</th>
                <th class="py-2 font-medium">Exit</th>
                <th class="py-2 font-medium">P&amp;L</th>
                <th class="py-2 font-medium">Exit reason</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="trade in backtests.active.trades" :key="trade.id" class="border-b border-border last:border-0">
                <td class="py-2 font-medium text-ink-950">{{ trade.symbol }}</td>
                <td class="py-2 text-xs text-slate-600">{{ formatDateTime(trade.entry_at) }}</td>
                <td class="py-2 text-xs text-slate-600">{{ formatDateTime(trade.exit_at) }}</td>
                <td class="tabular-nums py-2 font-medium" :class="Number(trade.pnl) >= 0 ? 'text-success' : 'text-danger'">{{ formatCurrency(trade.pnl, { signed: true }) }}</td>
                <td class="py-2 text-xs capitalize text-slate-600">{{ trade.exit_reason?.replaceAll('_', ' ') ?? '--' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
