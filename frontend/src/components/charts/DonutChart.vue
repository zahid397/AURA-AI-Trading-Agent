<script setup lang="ts">
import { computed } from 'vue'
import { formatCompactCurrency, formatPercent } from '@/utils/format'
import type { AllocationSlice } from '@/types/api'
import { BASE_TEXT_STYLE, VChart } from './echarts'

const props = defineProps<{ slices: AllocationSlice[] }>()

const PALETTE = ['#2563EB', '#10B981', '#F59E0B', '#8B5CF6', '#0EA5E9', '#94A3B8']

const option = computed(() => ({
  textStyle: BASE_TEXT_STYLE,
  tooltip: {
    trigger: 'item',
    backgroundColor: '#0F172A',
    borderWidth: 0,
    textStyle: { color: '#F8FAFC', fontSize: 12 },
    formatter: (p: { name: string; value: number; percent: number }) => `${p.name}: ${formatCompactCurrency(p.value)} (${p.percent}%)`,
  },
  legend: { bottom: 0, left: 'center', textStyle: { color: '#475569', fontSize: 11 }, itemWidth: 10, itemHeight: 10 },
  series: [
    {
      type: 'pie',
      radius: ['52%', '78%'],
      center: ['50%', '44%'],
      avoidLabelOverlap: true,
      itemStyle: { borderColor: '#FFFFFF', borderWidth: 2 },
      label: { show: false },
      labelLine: { show: false },
      color: PALETTE,
      data: props.slices.map((s) => ({ name: s.label, value: Number.parseFloat(s.value) })),
    },
  ],
}))

const total = computed(() => props.slices.reduce((sum, s) => sum + Number.parseFloat(s.value), 0))
</script>

<template>
  <div>
    <VChart :option="option" autoresize style="height: 240px; width: 100%" />
    <p class="sr-only">
      Allocation breakdown, total {{ formatCompactCurrency(total) }}:
      {{ slices.map((s) => `${s.label} ${formatPercent(s.pct, { alreadyPercent: true })}`).join(', ') }}.
    </p>
  </div>
</template>
