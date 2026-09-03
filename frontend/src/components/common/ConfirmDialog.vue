<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { AlertTriangle } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    confirmLabel?: string
    cancelLabel?: string
    danger?: boolean
    loading?: boolean
  }>(),
  {
    description: undefined,
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    danger: false,
    loading: false,
  },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const dialogRef = ref<HTMLDivElement | null>(null)

function onKeydown(e: KeyboardEvent): void {
  if (!props.open) return
  if (e.key === 'Escape') emit('cancel')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) requestAnimationFrame(() => dialogRef.value?.focus())
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/40 p-4"
      role="presentation"
      @click.self="emit('cancel')"
    >
      <div
        ref="dialogRef"
        class="w-full max-w-md rounded-card border border-border bg-surface p-5 shadow-lg focus:outline-none animate-fade-in"
        role="alertdialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
      >
        <div class="flex items-start gap-3">
          <div
            v-if="danger"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-danger-50 text-danger"
            aria-hidden="true"
          >
            <AlertTriangle :size="18" />
          </div>
          <div class="min-w-0">
            <h2 class="text-base font-semibold text-ink-950">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-slate-600">{{ description }}</p>
            <div v-if="$slots.default" class="mt-3">
              <slot />
            </div>
          </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas"
            :disabled="loading"
            @click="emit('cancel')"
          >
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="rounded-control px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60"
            :class="danger ? 'bg-danger hover:bg-danger/90' : 'bg-brand-600 hover:bg-brand-700'"
            :disabled="loading"
            @click="emit('confirm')"
          >
            {{ loading ? 'Working…' : confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
