import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as settingsService from '@/services/settings'
import { useUiNotificationsStore } from './uiNotifications'
import type { AppSettings, MarketClock, SystemHealth } from '@/types/api'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref<AppSettings | null>(null)
  const systemHealth = ref<SystemHealth | null>(null)
  const marketClock = ref<MarketClock | null>(null)

  const isLoading = ref(false)
  const isSaving = ref(false)
  const error = ref<string | null>(null)

  async function loadAll(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const [settingsResult, healthResult, clockResult] = await Promise.all([
        settingsService.fetchSettings(),
        settingsService.fetchSystemHealth(),
        settingsService.fetchMarketClock(),
      ])
      settings.value = settingsResult.data
      systemHealth.value = healthResult.data
      marketClock.value = clockResult.data
      ui.setFallback('settings', settingsResult.fallbackReason ?? healthResult.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load settings.'
    } finally {
      isLoading.value = false
    }
  }

  async function save(partial: Partial<AppSettings>): Promise<boolean> {
    const ui = useUiNotificationsStore()
    isSaving.value = true
    try {
      const updated = await settingsService.updateSettings(partial, settings.value)
      settings.value = updated
      ui.success('Settings saved.')
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to save settings.')
      return false
    } finally {
      isSaving.value = false
    }
  }

  async function refreshMarketClock(): Promise<void> {
    try {
      const result = await settingsService.fetchMarketClock()
      marketClock.value = result.data
    } catch {
      // Non-fatal -- the topbar clock simply keeps its last known value.
    }
  }

  return { settings, systemHealth, marketClock, isLoading, isSaving, error, loadAll, save, refreshMarketClock }
})
