<script setup lang="ts">
import { computed, type FunctionalComponent } from 'vue'
import { Minus, TrendingDown, TrendingUp } from 'lucide-vue-next'
import Skeleton from './Skeleton.vue'

const props = withDefaults(
  defineProps<{
    label: string
    value: string
    delta?: string | null
    direction?: 'up' | 'down' | 'flat'
    icon?: FunctionalComponent | null
    loading?: boolean
    hint?: string
  }>(),
  {
    delta: null,
    direction: 'flat',
    icon: null,
    loading: false,
    hint: undefined,
  },
)

const deltaIcon = computed(() => (props.direction === 'up' ? TrendingUp : props.direction === 'down' ? TrendingDown : Minus))
const deltaClass = computed(() =>
  props.direction === 'up' ? 'text-success' : props.direction === 'down' ? 'text-danger' : 'text-slate-600',
)
</script>

<template>
  <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
    <div class="flex items-center justify-between gap-2">
      <p class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ label }}</p>
      <component :is="icon" v-if="icon" :size="16" class="text-slate-600" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <Skeleton height="1.75rem" width="70%" rounded="0.375rem" class="mt-2" />
      <Skeleton height="0.875rem" width="45%" rounded="0.375rem" class="mt-2" />
    </template>
    <template v-else>
      <p class="tabular-nums mt-1 text-2xl font-semibold text-ink-950">{{ value }}</p>
      <p v-if="delta" class="mt-1 flex items-center gap-1 text-xs font-medium" :class="deltaClass">
        <component :is="deltaIcon" :size="13" aria-hidden="true" />
        <span class="tabular-nums">{{ delta }}</span>
      </p>
      <p v-else-if="hint" class="mt-1 text-xs text-slate-600">{{ hint }}</p>
    </template>
  </div>
</template>
