<script setup lang="ts">
import { computed } from 'vue'
import { formatPercent } from '@/utils/format'

const props = defineProps<{
  data: { month: string; return_pct: number }[]
}>()

const maxAbs = computed(() => Math.max(1, ...props.data.map((d) => Math.abs(d.return_pct))))

function cellStyle(value: number): Record<string, string> {
  const intensity = Math.min(1, Math.abs(value) / maxAbs.value)
  const color = value >= 0 ? `rgba(16, 185, 129, ${0.12 + intensity * 0.68})` : `rgba(239, 68, 68, ${0.12 + intensity * 0.68})`
  return { backgroundColor: color }
}

function monthLabel(month: string): string {
  const [y, m] = month.split('-')
  if (!y || !m) return month
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' })
}
</script>

<template>
  <div>
    <table class="w-full border-separate" style="border-spacing: 4px">
      <caption class="sr-only">
        Monthly returns heatmap, green for positive months, red for negative months.
      </caption>
      <tbody>
        <tr>
          <td
            v-for="cell in data"
            :key="cell.month"
            class="rounded-md p-2 text-center"
            :style="cellStyle(cell.return_pct)"
            :aria-label="`${monthLabel(cell.month)}: ${formatPercent(cell.return_pct, { alreadyPercent: true, signed: true, digits: 1 })}`"
          >
            <p class="text-[10px] font-medium text-slate-600">{{ monthLabel(cell.month) }}</p>
            <p class="tabular-nums text-xs font-semibold" :class="cell.return_pct >= 0 ? 'text-success' : 'text-danger'">
              {{ formatPercent(cell.return_pct, { alreadyPercent: true, signed: true, digits: 1 }) }}
            </p>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
