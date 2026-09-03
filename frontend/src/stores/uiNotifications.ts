import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'

export type ToastKind = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: string
  kind: ToastKind
  message: string
}

let toastSeq = 0

/**
 * Cross-cutting UI state: toasts, app-shell layout (sidebar/drawer), and a
 * registry of which domains are currently rendering fixture data because a
 * *live* request failed (as opposed to fixture mode being the deliberate
 * dev default) -- the topbar's degraded-service banner reads this.
 */
export const useUiNotificationsStore = defineStore('uiNotifications', () => {
  const toasts = ref<Toast[]>([])

  function pushToast(kind: ToastKind, message: string, timeoutMs = 5000): string {
    const id = `toast-${++toastSeq}`
    toasts.value.push({ id, kind, message })
    if (timeoutMs > 0) {
      setTimeout(() => dismissToast(id), timeoutMs)
    }
    return id
  }

  function dismissToast(id: string): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  const success = (message: string): string => pushToast('success', message)
  const error = (message: string): string => pushToast('error', message)
  const info = (message: string): string => pushToast('info', message)
  const warning = (message: string): string => pushToast('warning', message)

  // domain key -> reason the live call failed (only set on genuine live-mode fallback)
  const fallbackDomains = reactive<Record<string, string>>({})

  function setFallback(domain: string, reason: string | null): void {
    if (reason) fallbackDomains[domain] = reason
    else delete fallbackDomains[domain]
  }

  const degradedDomains = computed(() => Object.keys(fallbackDomains))
  const isDegraded = computed(() => degradedDomains.value.length > 0)

  const sidebarCollapsed = ref(false)
  const mobileDrawerOpen = ref(false)

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }
  function toggleMobileDrawer(open?: boolean): void {
    mobileDrawerOpen.value = open ?? !mobileDrawerOpen.value
  }

  return {
    toasts,
    pushToast,
    dismissToast,
    success,
    error,
    info,
    warning,
    fallbackDomains,
    setFallback,
    degradedDomains,
    isDegraded,
    sidebarCollapsed,
    mobileDrawerOpen,
    toggleSidebar,
    toggleMobileDrawer,
  }
})
