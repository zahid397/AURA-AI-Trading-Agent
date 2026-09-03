<script setup lang="ts">
import { WifiOff } from 'lucide-vue-next'

defineProps<{
  reason?: string | null
}>()
</script>

<template>
  <div
    v-if="reason"
    class="flex items-center gap-2 rounded-control border border-warning/30 bg-warning-50 px-3 py-2 text-xs font-medium text-warning"
    role="status"
  >
    <WifiOff :size="14" class="shrink-0" aria-hidden="true" />
    <span>Showing demo data — API unreachable ({{ reason }}).</span>
  </div>
</template>
