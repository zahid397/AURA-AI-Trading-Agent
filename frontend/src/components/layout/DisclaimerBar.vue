<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next'
</script>

<template>
  <div class="flex items-center justify-center gap-1.5 border-b border-warning/30 bg-warning-50 px-3 py-1 text-center text-[11px] font-medium text-warning">
    <AlertTriangle :size="12" class="shrink-0" aria-hidden="true" />
    <span>Paper trading only. Educational demonstration — not investment advice.</span>
  </div>
</template>
