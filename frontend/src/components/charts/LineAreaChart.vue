<script setup lang="ts">
import { computed } from 'vue'
import { BASE_TEXT_STYLE, VChart } from './echarts'

export interface LineSeriesInput {
  name: string
  points: { t: string; value: number }[]
  color: string
  area?: boolean
}

const props = withDefaults(
  defineProps<{
    series: LineSeriesInput[]
    height?: string
    valueFormatter?: (v: number) => string
    showLegend?: boolean
  }>(),
  {
    height: '260px',
    valueFormatter: (v: number) => v.toFixed(2),
    showLegend: false,
  },
)

const option = computed(() => ({
  textStyle: BASE_TEXT_STYLE,
  grid: { left: 8, right: 12, top: props.showLegend ? 32 : 12, bottom: 8, containLabel: true },
  legend: props.showLegend ? { top: 0, left: 0, textStyle: { color: '#475569', fontSize: 12 } } : undefined,
  tooltip: {
    trigger: 'axis',
    valueFormatter: (v: number | string) => props.valueFormatter(Number(v)),
    backgroundColor: '#0F172A',
    borderWidth: 0,
    textStyle: { color: '#F8FAFC', fontSize: 12 },
  },
  xAxis: {
    type: 'time',
    axisLine: { lineStyle: { color: '#E2E8F0' } },
    axisLabel: { color: '#94A3B8', fontSize: 11 },
    splitLine: { show: false },
  },
  yAxis: {
    type: 'value',
    scale: true,
    axisLabel: { color: '#94A3B8', fontSize: 11, formatter: (v: number) => props.valueFormatter(v) },
    splitLine: { lineStyle: { color: '#F1F5F9' } },
  },
  series: props.series.map((s) => ({
    name: s.name,
    type: 'line',
    showSymbol: false,
    smooth: 0.15,
    lineStyle: { color: s.color, width: 2 },
    itemStyle: { color: s.color },
    areaStyle: s.area
      ? {
          color: {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
              { offset: 0, color: `${s.color}33` },
              { offset: 1, color: `${s.color}00` },
            ],
          },
        }
      : undefined,
    data: s.points.map((p) => [p.t, p.value]),
  })),
}))
</script>

<template>
  <VChart :option="option" autoresize :style="{ height, width: '100%' }" />
</template>
