<script setup lang="ts">
import { AlertTriangle, RefreshCw } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    message?: string
  }>(),
  {
    message: 'Something went wrong loading this data.',
  },
)

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2 rounded-card border border-danger/20 bg-danger-50 px-6 py-8 text-center">
    <AlertTriangle :size="24" class="text-danger" aria-hidden="true" />
    <p class="text-sm font-medium text-ink-950">{{ message }}</p>
    <button
      type="button"
      class="mt-1 inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas"
      @click="emit('retry')"
    >
      <RefreshCw :size="14" aria-hidden="true" />
      Retry
    </button>
  </div>
</template>
