<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Loader2, RotateCcw, Search, X } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import PayoffChart from '@/components/charts/PayoffChart.vue'
import { startOptionScan, fetchOptionScan } from '@/services/strategies'
import { useStrategiesStore } from '@/stores/strategies'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatCurrency, formatDateTime, formatPercent } from '@/utils/format'
import type { OptionScan, OptionScanFilters } from '@/types/api'

const strategies = useStrategiesStore()
const ui = useUiNotificationsStore()
const router = useRouter()

const filterForm = reactive<OptionScanFilters>({ direction: 'any', strategy: 'any' })
const scan = ref<OptionScan | null>(null)
const isScanning = ref(false)

onMounted(() => void strategies.loadOpportunities(filterForm))

function applyFilters(): void {
  void strategies.loadOpportunities({ ...filterForm })
}
function resetFilters(): void {
  Object.assign(filterForm, { direction: 'any', strategy: 'any', dte_min: undefined, dte_max: undefined, iv_percentile_min: undefined, min_confidence: undefined, max_risk: undefined, min_open_interest: undefined, min_volume: undefined, max_spread_pct: undefined })
  applyFilters()
}

async function runScan(): Promise<void> {
  isScanning.value = true
  scan.value = null
  try {
    const { scan_id } = await startOptionScan(['SPY', 'NVDA', 'TSLA'], filterForm)
    const result = await fetchOptionScan(scan_id)
    scan.value = result.data
  } catch {
    // There's no dedicated scan-progress endpoint on the backend yet, so this optional
    // progress widget just won't populate -- a scan is really "refresh opportunities" from
    // the user's point of view, so fall through to the real refresh below either way instead
    // of leaving them at a dead end.
    ui.info('Live scan progress isn\'t available yet — refreshing opportunities directly.')
  }
  try {
    await strategies.loadOpportunities(filterForm)
  } finally {
    isScanning.value = false
  }
}
function dismissScan(): void {
  scan.value = null
}

async function select(id: string): Promise<void> {
  await strategies.selectStrategy(id)
}

function freshnessLabel(quoteTime: string): string {
  const seconds = Math.round((Date.now() - new Date(quoteTime).getTime()) / 1000)
  if (seconds < 60) return `${seconds}s ago`
  return `${Math.round(seconds / 60)}m ago`
}

const detail = computed(() => strategies.detail)
const payoff = computed(() => strategies.payoff)
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['scanner'] ?? null" />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[260px_1fr]">
      <!-- Filter rail -->
      <aside class="h-fit space-y-4 rounded-card border border-border bg-surface p-4 shadow-sm lg:sticky lg:top-[76px]">
        <h2 class="text-sm font-semibold text-ink-950">Filters</h2>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Direction</label>
          <select v-model="filterForm.direction" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm">
            <option value="any">Any</option>
            <option value="bullish">Bullish</option>
            <option value="bearish">Bearish</option>
            <option value="neutral">Neutral</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Strategy</label>
          <select v-model="filterForm.strategy" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm">
            <option value="any">Any</option>
            <option value="bull_call_spread">Bull Call Spread</option>
            <option value="bear_put_spread">Bear Put Spread</option>
            <option value="iron_condor">Iron Condor</option>
            <option value="covered_call">Covered Call</option>
            <option value="cash_secured_put">Cash Secured Put</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">DTE min</label>
            <input v-model.number="filterForm.dte_min" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">DTE max</label>
            <input v-model.number="filterForm.dte_max" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Min IV percentile</label>
          <input v-model.number="filterForm.iv_percentile_min" type="number" min="0" max="100" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Min confidence</label>
          <input v-model.number="filterForm.min_confidence" type="number" min="0" max="1" step="0.05" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Max risk ($)</label>
          <input v-model.number="filterForm.max_risk" type="number" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Min OI</label>
            <input v-model.number="filterForm.min_open_interest" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Min volume</label>
            <input v-model.number="filterForm.min_volume" type="number" class="w-full rounded-control border border-border bg-surface px-2 py-1.5 text-sm" />
          </div>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Max spread %</label>
          <input v-model.number="filterForm.max_spread_pct" type="number" step="0.01" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
        </div>
        <div class="flex gap-2 pt-1">
          <button type="button" class="flex-1 rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">Apply</button>
          <button type="button" class="rounded-control border border-border px-3 py-1.5 text-sm text-ink-950 hover:bg-canvas" aria-label="Reset filters" @click="resetFilters"><RotateCcw :size="14" /></button>
        </div>

        <div class="border-t border-border pt-3">
          <button type="button" class="flex w-full items-center justify-center gap-1.5 rounded-control border border-brand-200 bg-brand-50 px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-100 disabled:opacity-60" :disabled="isScanning" @click="runScan">
            <Loader2 v-if="isScanning" :size="14" class="animate-spin" />
            <Search v-else :size="14" />
            {{ isScanning ? 'Scanning…' : 'Scan Market' }}
          </button>
          <div v-if="scan" class="mt-2 rounded-control border border-border bg-canvas p-2 text-xs">
            <div class="flex items-center justify-between">
              <span class="text-slate-600">Scan progress</span>
              <button type="button" aria-label="Dismiss scan status" @click="dismissScan"><X :size="12" class="text-slate-600" /></button>
            </div>
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-border">
              <div class="h-full bg-brand-600" :style="{ width: `${scan.progress_pct}%` }" />
            </div>
            <p class="mt-1 text-slate-600">{{ scan.result_count }} opportunities · {{ scan.status }}</p>
          </div>
        </div>

        <div v-if="strategies.sentiment.length" class="border-t border-border pt-3">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Sentiment &amp; volatility</h3>
          <ul class="space-y-1.5 text-xs">
            <li v-for="s in strategies.sentiment" :key="s.symbol" class="flex items-center justify-between">
              <span class="font-medium text-ink-950">{{ s.symbol }}</span>
              <span class="capitalize text-slate-600">{{ s.trend }} · IV {{ s.iv_percentile }}</span>
            </li>
          </ul>
        </div>
      </aside>

      <!-- Results + detail split view -->
      <div class="space-y-5">
        <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <div v-if="isScanning" class="space-y-2">
            <div class="flex items-center gap-1.5 text-sm font-medium text-slate-600">
              <Loader2 :size="14" class="animate-spin" />
              Loading data…
            </div>
            <Skeleton v-for="i in 3" :key="i" height="3rem" />
          </div>
          <div v-else-if="strategies.isLoadingList && strategies.opportunities.length === 0" class="space-y-2">
            <Skeleton v-for="i in 3" :key="i" height="3rem" />
          </div>
          <ErrorRetry v-else-if="strategies.error" :message="strategies.error" @retry="applyFilters" />
          <EmptyState v-else-if="strategies.opportunities.length === 0" title="No opportunities match these filters" action-label="Reset filters" @action="resetFilters" />
          <div v-else class="scroll-x">
            <table class="w-full min-w-[860px] text-sm">
              <thead>
                <tr class="border-b border-border text-left text-xs text-slate-600">
                  <th class="py-2 font-medium">Symbol</th>
                  <th class="py-2 font-medium">Strategy</th>
                  <th class="py-2 font-medium">Regime</th>
                  <th class="py-2 font-medium">Confidence</th>
                  <th class="py-2 font-medium">Max risk/profit</th>
                  <th class="py-2 font-medium">POP</th>
                  <th class="py-2 font-medium">Liquidity</th>
                  <th class="py-2 font-medium">Freshness</th>
                  <th class="py-2 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="opp in strategies.opportunities"
                  :key="opp.id"
                  class="cursor-pointer border-b border-border last:border-0 hover:bg-canvas"
                  :class="{ 'bg-brand-50': strategies.selectedId === opp.id }"
                  @click="select(opp.id)"
                >
                  <td class="py-2 font-medium text-ink-950">{{ opp.symbol }}</td>
                  <td class="py-2 capitalize text-slate-600">{{ opp.strategy_type?.replaceAll('_', ' ') ?? '--' }}</td>
                  <td class="py-2 capitalize text-slate-600">{{ opp.regime }}</td>
                  <td class="tabular-nums py-2">{{ formatPercent(opp.confidence) }}</td>
                  <td class="tabular-nums py-2">-{{ formatCurrency(opp.max_loss) }} / {{ formatCurrency(opp.max_profit) }}</td>
                  <td class="tabular-nums py-2">{{ formatPercent(opp.probability_of_profit) }}</td>
                  <td class="tabular-nums py-2">{{ formatPercent(opp.liquidity_score) }}</td>
                  <td class="py-2 text-xs text-slate-600">{{ freshnessLabel(opp.quote_timestamp) }}</td>
                  <td class="py-2">
                    <button type="button" class="text-xs font-medium text-brand-700 hover:underline" @click.stop="router.push({ name: 'strategy-analysis', params: { id: opp.id } })">Analyze →</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="strategies.selectedId" class="rounded-card border border-border bg-surface p-4 shadow-sm">
          <div v-if="strategies.isLoadingDetail" class="space-y-2">
            <Skeleton height="1.5rem" width="30%" />
            <Skeleton height="16rem" />
          </div>
          <template v-else-if="detail">
            <div class="mb-3 flex items-center justify-between">
              <h2 class="text-sm font-semibold text-ink-950">{{ detail.symbol }} detail</h2>
              <Badge tone="info" class="capitalize">{{ detail.strategy_type?.replaceAll('_', ' ') ?? '--' }}</Badge>
            </div>
            <PayoffChart v-if="payoff" :curve="payoff" />
            <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
              <div><p class="text-xs text-slate-600">Score</p><p class="tabular-nums font-semibold text-ink-950">{{ formatPercent(detail.score) }}</p></div>
              <div><p class="text-xs text-slate-600">Quote</p><p class="text-xs text-ink-950">{{ formatDateTime(detail.quote_timestamp) }}</p></div>
              <div><p class="text-xs text-slate-600">Legs</p><p class="tabular-nums font-semibold text-ink-950">{{ detail.legs.length }}</p></div>
              <div><p class="text-xs text-slate-600">DTE</p><p class="tabular-nums font-semibold text-ink-950">{{ detail.dte }}</p></div>
            </div>
            <router-link :to="{ name: 'strategy-analysis', params: { id: detail.id } }" class="mt-3 inline-block text-xs font-semibold text-brand-700 hover:underline">Open full analysis →</router-link>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
