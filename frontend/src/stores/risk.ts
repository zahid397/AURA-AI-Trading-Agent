import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as riskService from '@/services/risk'
import { useUiNotificationsStore } from './uiNotifications'
import type { RiskAlert, RiskOverview, RiskPolicy, RiskPolicyLimits } from '@/types/api'

export const useRiskStore = defineStore('risk', () => {
  const overview = ref<RiskOverview | null>(null)
  const policy = ref<RiskPolicy | null>(null)
  const policyHistory = ref<RiskPolicy[]>([])
  const alerts = ref<RiskAlert[]>([])

  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const isSavingPolicy = ref(false)

  async function loadAll(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const [overviewResult, policyResult, historyResult, alertsResult] = await Promise.all([
        riskService.fetchRiskOverview(),
        riskService.fetchRiskPolicy(),
        riskService.fetchRiskPolicyHistory(),
        riskService.fetchRiskAlerts(),
      ])
      overview.value = overviewResult.data
      policy.value = policyResult.data
      policyHistory.value = historyResult.data
      alerts.value = alertsResult.data
      ui.setFallback('risk', overviewResult.fallbackReason ?? policyResult.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load risk data.'
    } finally {
      isLoading.value = false
    }
  }

  async function savePolicy(limits: RiskPolicyLimits): Promise<boolean> {
    const ui = useUiNotificationsStore()
    isSavingPolicy.value = true
    try {
      const updated = await riskService.updateRiskPolicy(limits)
      policy.value = updated
      policyHistory.value = [...policyHistory.value, updated]
      ui.success(`Risk policy updated to version ${updated.version}.`)
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to update risk policy.')
      return false
    } finally {
      isSavingPolicy.value = false
    }
  }

  async function toggleKillSwitch(engaged: boolean): Promise<boolean> {
    const ui = useUiNotificationsStore()
    try {
      const result = await riskService.setKillSwitch(engaged)
      if (overview.value) overview.value.kill_switch_engaged = result.engaged
      ui.warning(engaged ? 'Kill switch engaged -- all trading halted.' : 'Kill switch disengaged.')
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to toggle kill switch.')
      return false
    }
  }

  return { overview, policy, policyHistory, alerts, isLoading, error, isSavingPolicy, loadAll, savePolicy, toggleKillSwitch }
})
