import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as strategiesService from '@/services/strategies'
import { useUiNotificationsStore } from './uiNotifications'
import type { OptionScanFilters, PayoffCurve, SentimentSummary, Strategy, StrategyDetail } from '@/types/api'

const DEFAULT_FILTERS: OptionScanFilters = { direction: 'any', strategy: 'any' }

/** Backs the Options Scanner page (list + filters) and the Strategy Analysis page (detail + payoff). */
export const useStrategiesStore = defineStore('strategies', () => {
  const opportunities = ref<Strategy[]>([])
  const sentiment = ref<SentimentSummary[]>([])
  const filters = ref<OptionScanFilters>({ ...DEFAULT_FILTERS })
  const selectedId = ref<string | null>(null)

  const detail = ref<StrategyDetail | null>(null)
  const payoff = ref<PayoffCurve | null>(null)

  const isLoadingList = ref(false)
  const isLoadingDetail = ref(false)
  const error = ref<string | null>(null)
  const detailError = ref<string | null>(null)

  async function loadOpportunities(nextFilters: OptionScanFilters = filters.value): Promise<void> {
    const ui = useUiNotificationsStore()
    filters.value = nextFilters
    isLoadingList.value = true
    error.value = null
    try {
      const [oppResult, sentimentResult] = await Promise.all([
        strategiesService.fetchOptionOpportunities(nextFilters),
        strategiesService.fetchSentimentSummaries(),
      ])
      opportunities.value = oppResult.data
      sentiment.value = sentimentResult.data
      ui.setFallback('scanner', oppResult.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load option opportunities.'
    } finally {
      isLoadingList.value = false
    }
  }

  async function selectStrategy(id: string): Promise<void> {
    const ui = useUiNotificationsStore()
    selectedId.value = id
    isLoadingDetail.value = true
    detailError.value = null
    try {
      const [detailResult, payoffResult] = await Promise.all([strategiesService.fetchStrategyDetail(id), strategiesService.fetchPayoffCurve(id)])
      detail.value = detailResult.data
      payoff.value = payoffResult.data
      ui.setFallback('strategy-detail', detailResult.fallbackReason ?? null)
    } catch (err) {
      detailError.value = err instanceof Error ? err.message : 'Failed to load strategy detail.'
      detail.value = null
      payoff.value = null
    } finally {
      isLoadingDetail.value = false
    }
  }

  function resetFilters(): void {
    filters.value = { ...DEFAULT_FILTERS }
  }

  return {
    opportunities,
    sentiment,
    filters,
    selectedId,
    detail,
    payoff,
    isLoadingList,
    isLoadingDetail,
    error,
    detailError,
    loadOpportunities,
    selectStrategy,
    resetFilters,
  }
})
