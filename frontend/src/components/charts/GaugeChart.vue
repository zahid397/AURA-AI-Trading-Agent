<script setup lang="ts">
import { computed } from 'vue'
import { BASE_TEXT_STYLE, CHART_COLORS, VChart } from './echarts'

const props = withDefaults(
  defineProps<{
    value: number
    max?: number
    label: string
  }>(),
  { max: 100 },
)

const option = computed(() => ({
  textStyle: BASE_TEXT_STYLE,
  series: [
    {
      type: 'gauge',
      min: 0,
      max: props.max,
      startAngle: 210,
      endAngle: -30,
      radius: '95%',
      progress: { show: true, width: 14, itemStyle: { color: CHART_COLORS.brand } },
      axisLine: {
        lineStyle: {
          width: 14,
          color: [
            [0.4, CHART_COLORS.success],
            [0.7, CHART_COLORS.warning],
            [1, CHART_COLORS.danger],
          ],
        },
      },
      pointer: { show: false },
      axisTick: { show: false },
      splitLine: { show: false },
      axisLabel: { show: false },
      anchor: { show: false },
      detail: {
        valueAnimation: true,
        fontSize: 28,
        fontWeight: 700,
        color: '#0F172A',
        offsetCenter: [0, '-8%'],
        formatter: (v: number) => `${Math.round(v)}`,
      },
      title: { offsetCenter: [0, '30%'], fontSize: 12, color: '#475569' },
      data: [{ value: props.value, name: props.label }],
    },
  ],
}))
</script>

<template>
  <div>
    <VChart :option="option" autoresize style="height: 200px; width: 100%" />
    <p class="sr-only">{{ label }}: {{ value }} out of {{ max }}.</p>
  </div>
</template>
