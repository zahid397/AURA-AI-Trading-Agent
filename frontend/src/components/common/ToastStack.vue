<script setup lang="ts">
import { computed } from 'vue'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import type { ToastKind } from '@/stores/uiNotifications'

const ui = useUiNotificationsStore()
const toasts = computed(() => ui.toasts)

const ICONS: Record<ToastKind, typeof CheckCircle2> = {
  success: CheckCircle2,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
}

const TONE_CLASSES: Record<ToastKind, string> = {
  success: 'border-success/20 bg-success-50 text-success',
  error: 'border-danger/20 bg-danger-50 text-danger',
  warning: 'border-warning/30 bg-warning-50 text-warning',
  info: 'border-info/20 bg-info-50 text-info',
}
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2" aria-live="polite" role="status">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="pointer-events-auto flex items-start gap-2 rounded-card border bg-surface p-3 shadow-lg"
          :class="TONE_CLASSES[toast.kind]"
        >
          <component :is="ICONS[toast.kind]" :size="18" class="mt-0.5 shrink-0" aria-hidden="true" />
          <p class="flex-1 text-sm font-medium text-ink-950">{{ toast.message }}</p>
          <button type="button" class="shrink-0 rounded p-0.5 text-slate-600 hover:bg-canvas" aria-label="Dismiss notification" @click="ui.dismissToast(toast.id)">
            <X :size="14" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 180ms ease;
}
.toast-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(8px);
}
@media (prefers-reduced-motion: reduce) {
  .toast-enter-active,
  .toast-leave-active {
    transition: none;
  }
}
</style>
