<script setup lang="ts">
import { computed } from 'vue'
import { formatCurrency, formatPercent } from '@/utils/format'
import type { EquityCurvePoint, EquityCurveRange } from '@/types/api'
import { CHART_COLORS } from './echarts'
import LineAreaChart from './LineAreaChart.vue'
import Skeleton from '../common/Skeleton.vue'

const props = withDefaults(
  defineProps<{
    points: EquityCurvePoint[]
    range: EquityCurveRange
    loading?: boolean
  }>(),
  { loading: false },
)

const emit = defineEmits<{ 'update:range': [EquityCurveRange] }>()

const RANGES: EquityCurveRange[] = ['1D', '1W', '1M', '3M', '1Y', 'ALL']

const series = computed(() => [
  { name: 'Equity', points: props.points.map((p) => ({ t: p.t, value: Number.parseFloat(p.equity) })), color: CHART_COLORS.brand, area: true },
])

const summary = computed(() => {
  if (props.points.length < 2) return null
  const first = Number.parseFloat(props.points[0]?.equity ?? '0')
  const last = Number.parseFloat(props.points[props.points.length - 1]?.equity ?? '0')
  const changePct = first !== 0 ? (last - first) / first : 0
  const values = props.points.map((p) => Number.parseFloat(p.equity))
  return {
    first,
    last,
    changePct,
    min: Math.min(...values),
    max: Math.max(...values),
  }
})
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div v-if="summary" class="tabular-nums text-sm text-slate-600">
        <span class="font-semibold text-ink-950">{{ formatCurrency(summary.last) }}</span>
        <span class="ml-2" :class="summary.changePct >= 0 ? 'text-success' : 'text-danger'">
          {{ formatPercent(summary.changePct, { signed: true, digits: 2 }) }}
        </span>
        <span class="ml-2 text-slate-600">over selected range</span>
      </div>
      <div role="tablist" aria-label="Equity curve range" class="flex gap-0.5 rounded-control border border-border bg-canvas p-0.5">
        <button
          v-for="r in RANGES"
          :key="r"
          type="button"
          role="tab"
          :aria-selected="range === r"
          class="rounded px-2 py-1 text-xs font-medium transition-colors"
          :class="range === r ? 'bg-surface text-brand-700 shadow-sm' : 'text-slate-600 hover:text-ink-950'"
          @click="emit('update:range', r)"
        >
          {{ r }}
        </button>
      </div>
    </div>
    <Skeleton v-if="loading" height="260px" rounded="0.75rem" />
    <LineAreaChart v-else :series="series" :value-formatter="(v) => formatCurrency(v)" />
    <p class="sr-only">
      Equity curve for range {{ range }}.
      <template v-if="summary">
        Started at {{ formatCurrency(summary.first) }}, ended at {{ formatCurrency(summary.last) }}, a change of
        {{ formatPercent(summary.changePct, { signed: true, digits: 2 }) }}. Range low {{ formatCurrency(summary.min) }}, high
        {{ formatCurrency(summary.max) }}.
      </template>
    </p>
  </div>
</template>
