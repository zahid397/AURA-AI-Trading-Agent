import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as dashboardService from '@/services/dashboard'
import * as proposalsService from '@/services/proposals'
import * as strategiesService from '@/services/strategies'
import { useUiNotificationsStore } from './uiNotifications'
import type { ActivityEntry, DashboardSummary, EquityCurvePoint, EquityCurveRange, SentimentSummary, Trade } from '@/types/api'

export const useDashboardStore = defineStore('dashboard', () => {
  const summary = ref<DashboardSummary | null>(null)
  const equityCurve = ref<EquityCurvePoint[]>([])
  const activity = ref<ActivityEntry[]>([])
  const equityRange = ref<EquityCurveRange>('1M')
  const marketOverview = ref<SentimentSummary[]>([])
  const recentTrades = ref<Trade[]>([])

  const isLoadingSummary = ref(false)
  const isLoadingCurve = ref(false)
  const isLoadingActivity = ref(false)
  const isLoadingExtras = ref(false)
  const summaryError = ref<string | null>(null)
  const curveError = ref<string | null>(null)
  const activityError = ref<string | null>(null)

  async function loadSummary(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoadingSummary.value = true
    summaryError.value = null
    try {
      const result = await dashboardService.fetchDashboardSummary()
      summary.value = result.data
      ui.setFallback('dashboard-summary', result.fallbackReason ?? null)
    } catch (err) {
      summaryError.value = err instanceof Error ? err.message : 'Failed to load dashboard summary.'
    } finally {
      isLoadingSummary.value = false
    }
  }

  async function loadEquityCurve(range: EquityCurveRange = equityRange.value): Promise<void> {
    const ui = useUiNotificationsStore()
    equityRange.value = range
    isLoadingCurve.value = true
    curveError.value = null
    try {
      const result = await dashboardService.fetchEquityCurve(range)
      equityCurve.value = result.data
      ui.setFallback('dashboard-equity-curve', result.fallbackReason ?? null)
    } catch (err) {
      curveError.value = err instanceof Error ? err.message : 'Failed to load equity curve.'
    } finally {
      isLoadingCurve.value = false
    }
  }

  async function loadActivity(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoadingActivity.value = true
    activityError.value = null
    try {
      const result = await dashboardService.fetchDashboardActivity()
      activity.value = result.data
      ui.setFallback('dashboard-activity', result.fallbackReason ?? null)
    } catch (err) {
      activityError.value = err instanceof Error ? err.message : 'Failed to load AI activity.'
    } finally {
      isLoadingActivity.value = false
    }
  }

  async function loadExtras(): Promise<void> {
    isLoadingExtras.value = true
    try {
      const [sentimentResult, tradesResult] = await Promise.all([strategiesService.fetchSentimentSummaries(), proposalsService.fetchTrades()])
      marketOverview.value = sentimentResult.data
      recentTrades.value = tradesResult.data.slice(-6).reverse()
    } finally {
      isLoadingExtras.value = false
    }
  }

  async function loadAll(): Promise<void> {
    await Promise.all([loadSummary(), loadEquityCurve(), loadActivity(), loadExtras()])
  }

  return {
    summary,
    equityCurve,
    activity,
    equityRange,
    marketOverview,
    recentTrades,
    isLoadingSummary,
    isLoadingCurve,
    isLoadingActivity,
    isLoadingExtras,
    summaryError,
    curveError,
    activityError,
    loadSummary,
    loadEquityCurve,
    loadActivity,
    loadExtras,
    loadAll,
  }
})
