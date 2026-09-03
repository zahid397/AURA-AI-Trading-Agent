<script setup lang="ts">
import { computed } from 'vue'
import { formatCurrency } from '@/utils/format'
import type { PayoffCurve } from '@/types/api'
import { BASE_TEXT_STYLE, CHART_COLORS, VChart } from './echarts'

const props = defineProps<{ curve: PayoffCurve }>()

const data = computed(() => props.curve.points.map((p) => [Number.parseFloat(p.underlying_price), Number.parseFloat(p.pnl)]))
const currentPrice = computed(() => Number.parseFloat(props.curve.current_price))
const breakevens = computed(() => props.curve.breakevens.map((b) => Number.parseFloat(b)))

const option = computed(() => ({
  textStyle: BASE_TEXT_STYLE,
  grid: { left: 8, right: 16, top: 16, bottom: 8, containLabel: true },
  tooltip: {
    trigger: 'axis',
    backgroundColor: '#0F172A',
    borderWidth: 0,
    textStyle: { color: '#F8FAFC', fontSize: 12 },
    formatter: (params: unknown) => {
      const arr = Array.isArray(params) ? params : [params]
      const p = arr[0] as { data: [number, number] } | undefined
      if (!p) return ''
      const [price, pnl] = p.data
      return `Underlying: ${formatCurrency(price)}<br/>P&amp;L: ${formatCurrency(pnl, { signed: true })}`
    },
  },
  xAxis: {
    type: 'value',
    name: 'Underlying price',
    nameLocation: 'middle',
    nameGap: 28,
    nameTextStyle: { color: '#475569', fontSize: 11 },
    axisLabel: { color: '#94A3B8', fontSize: 11, formatter: (v: number) => formatCurrency(v, { maximumFractionDigits: 0 }) },
    axisLine: { lineStyle: { color: '#E2E8F0' } },
    splitLine: { show: false },
  },
  yAxis: {
    type: 'value',
    name: 'P&L',
    nameTextStyle: { color: '#475569', fontSize: 11 },
    axisLabel: { color: '#94A3B8', fontSize: 11, formatter: (v: number) => formatCurrency(v, { maximumFractionDigits: 0 }) },
    splitLine: { lineStyle: { color: '#F1F5F9' } },
  },
  series: [
    {
      type: 'line',
      showSymbol: false,
      lineStyle: { width: 2.5 },
      data: data.value,
      markLine: {
        symbol: 'none',
        label: { formatter: (p: { name: string }) => p.name, color: '#475569', fontSize: 11 },
        lineStyle: { type: 'dashed', color: '#94A3B8' },
        data: [
          { yAxis: 0, label: { formatter: 'Breakeven line' } },
          ...breakevens.value.map((b) => ({ xAxis: b, label: { formatter: `BE ${formatCurrency(b)}` } })),
          { xAxis: currentPrice.value, lineStyle: { color: CHART_COLORS.brand, type: 'solid' }, label: { formatter: `Current ${formatCurrency(currentPrice.value)}`, color: CHART_COLORS.brand } },
        ],
      },
    },
  ],
}))
</script>

<template>
  <div>
    <VChart :option="option" autoresize style="height: 320px; width: 100%" />
    <div class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
      <div>
        <p class="text-slate-600">Max profit</p>
        <p class="tabular-nums font-semibold text-success">{{ formatCurrency(curve.max_profit) }}</p>
      </div>
      <div>
        <p class="text-slate-600">Max loss</p>
        <p class="tabular-nums font-semibold text-danger">-{{ formatCurrency(curve.max_loss) }}</p>
      </div>
      <div>
        <p class="text-slate-600">Breakeven(s)</p>
        <p class="tabular-nums font-semibold text-ink-950">{{ curve.breakevens.map((b) => formatCurrency(b)).join(', ') }}</p>
      </div>
      <div>
        <p class="text-slate-600">Current price</p>
        <p class="tabular-nums font-semibold text-brand-700">{{ formatCurrency(curve.current_price) }}</p>
      </div>
    </div>
    <p class="sr-only">
      Payoff diagram from {{ formatCurrency(curve.points[0]?.underlying_price ?? 0) }} to
      {{ formatCurrency(curve.points[curve.points.length - 1]?.underlying_price ?? 0) }} underlying price. Max profit
      {{ formatCurrency(curve.max_profit) }}, max loss {{ formatCurrency(curve.max_loss) }}, breakeven at
      {{ curve.breakevens.map((b) => formatCurrency(b)).join(' and ') }}, current price {{ formatCurrency(curve.current_price) }}.
    </p>
  </div>
</template>
