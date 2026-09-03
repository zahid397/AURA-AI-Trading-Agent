<script setup lang="ts">
import { computed } from 'vue'
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    tone?: 'success' | 'danger' | 'warning' | 'info' | 'neutral'
    icon?: boolean
  }>(),
  {
    tone: 'neutral',
    icon: true,
  },
)

const toneClasses = computed(() => {
  switch (props.tone) {
    case 'success':
      return 'bg-success-50 text-success border-success/20'
    case 'danger':
      return 'bg-danger-50 text-danger border-danger/20'
    case 'warning':
      return 'bg-warning-50 text-warning border-warning/30'
    case 'info':
      return 'bg-info-50 text-info border-info/20'
    default:
      return 'bg-slate-100 text-slate-600 border-border'
  }
})

const IconComponent = computed(() => {
  switch (props.tone) {
    case 'success':
      return CheckCircle2
    case 'danger':
      return XCircle
    case 'warning':
      return AlertTriangle
    case 'info':
      return Info
    default:
      return null
  }
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium leading-5"
    :class="toneClasses"
  >
    <component :is="IconComponent" v-if="icon && IconComponent" :size="12" aria-hidden="true" />
    <slot />
  </span>
</template>
