<script setup lang="ts">
withDefaults(
  defineProps<{
    width?: string
    height?: string
    rounded?: string
  }>(),
  {
    width: '100%',
    height: '1rem',
    rounded: '0.5rem',
  },
)
</script>

<template>
  <div
    class="animate-pulse bg-slate-200/70"
    :style="{ width, height, borderRadius: rounded }"
    role="presentation"
    aria-hidden="true"
  />
</template>
