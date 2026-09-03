<script setup lang="ts">
import type { FunctionalComponent } from 'vue'
import { Inbox } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    icon?: FunctionalComponent
    title: string
    description?: string
    actionLabel?: string
  }>(),
  {
    icon: () => Inbox,
    description: undefined,
    actionLabel: undefined,
  },
)

const emit = defineEmits<{ action: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2 rounded-card border border-dashed border-border bg-canvas px-6 py-10 text-center">
    <component :is="icon" :size="28" class="text-slate-600" aria-hidden="true" />
    <p class="text-sm font-medium text-ink-950">{{ title }}</p>
    <p v-if="description" class="max-w-sm text-sm text-slate-600">{{ description }}</p>
    <button
      v-if="actionLabel"
      type="button"
      class="mt-2 rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700"
      @click="emit('action')"
    >
      {{ actionLabel }}
    </button>
  </div>
</template>
