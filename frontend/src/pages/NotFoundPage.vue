<script setup lang="ts">
import { CompassIcon } from 'lucide-vue-next'
</script>

<template>
  <div class="flex min-h-svh flex-col items-center justify-center gap-3 bg-canvas px-4 text-center">
    <CompassIcon :size="32" class="text-slate-600" />
    <h1 class="text-lg font-semibold text-ink-950">Page not found</h1>
    <p class="text-sm text-slate-600">The page you're looking for doesn't exist.</p>
    <router-link to="/dashboard" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">
      Back to Dashboard
    </router-link>
  </div>
</template>
