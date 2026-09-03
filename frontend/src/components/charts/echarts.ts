/**
 * Central ECharts registration (tree-shaken via echarts/core) so every
 * chart component just imports `VChart` from here instead of pulling in
 * the full echarts bundle or re-registering modules per-component.
 */
import { BarChart, GaugeChart, HeatmapChart, LineChart, PieChart } from 'echarts/charts'
import {
  CalendarComponent,
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  MarkPointComponent,
  TitleComponent,
  TooltipComponent,
  VisualMapComponent,
} from 'echarts/components'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import VChart from 'vue-echarts'

use([
  CanvasRenderer,
  LineChart,
  BarChart,
  PieChart,
  GaugeChart,
  HeatmapChart,
  TitleComponent,
  TooltipComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  MarkPointComponent,
  VisualMapComponent,
  DataZoomComponent,
  CalendarComponent,
])

export { VChart }

export const CHART_COLORS = {
  brand: '#2563EB',
  brandLight: '#93C5FD',
  success: '#10B981',
  danger: '#EF4444',
  warning: '#F59E0B',
  slate: '#94A3B8',
  ink: '#0F172A',
  border: '#E2E8F0',
} as const

export const BASE_TEXT_STYLE = {
  fontFamily: 'Inter, system-ui, sans-serif',
  color: '#475569',
}
