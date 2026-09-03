<script setup lang="ts">
import { computed } from 'vue'
import { BASE_TEXT_STYLE, CHART_COLORS, VChart } from './echarts'

const props = withDefaults(
  defineProps<{
    categories: string[]
    values: number[]
    height?: string
    colorByValue?: boolean
  }>(),
  { height: '220px', colorByValue: false },
)

const option = computed(() => ({
  textStyle: BASE_TEXT_STYLE,
  grid: { left: 8, right: 12, top: 12, bottom: 8, containLabel: true },
  tooltip: { trigger: 'axis', backgroundColor: '#0F172A', borderWidth: 0, textStyle: { color: '#F8FAFC', fontSize: 12 } },
  xAxis: {
    type: 'category',
    data: props.categories,
    axisLine: { lineStyle: { color: '#E2E8F0' } },
    axisLabel: { color: '#94A3B8', fontSize: 11 },
  },
  yAxis: { type: 'value', axisLabel: { color: '#94A3B8', fontSize: 11 }, splitLine: { lineStyle: { color: '#F1F5F9' } } },
  series: [
    {
      type: 'bar',
      barMaxWidth: 32,
      itemStyle: {
        color: props.colorByValue
          ? (params: { value: number }) => (params.value >= 0 ? CHART_COLORS.success : CHART_COLORS.danger)
          : CHART_COLORS.brand,
        borderRadius: [4, 4, 0, 0],
      },
      data: props.values,
    },
  ],
}))
</script>

<template>
  <VChart :option="option" autoresize :style="{ height, width: '100%' }" />
</template>
