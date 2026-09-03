import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as portfolioService from '@/services/portfolio'
import * as proposalsService from '@/services/proposals'
import { useUiNotificationsStore } from './uiNotifications'
import type { Order, PerformanceReport, PortfolioAllocation, PortfolioSummary, Position, Trade } from '@/types/api'

export const usePortfolioStore = defineStore('portfolio', () => {
  const summary = ref<PortfolioSummary | null>(null)
  const openPositions = ref<Position[]>([])
  const closedPositions = ref<Position[]>([])
  const allocation = ref<PortfolioAllocation | null>(null)
  const performance = ref<PerformanceReport | null>(null)
  const orders = ref<Order[]>([])
  const trades = ref<Trade[]>([])

  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function loadAll(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const [summaryResult, openResult, closedResult, allocationResult, performanceResult, ordersResult, tradesResult] = await Promise.all([
        portfolioService.fetchPortfolioSummary(),
        portfolioService.fetchPositions('open'),
        portfolioService.fetchPositions('closed'),
        portfolioService.fetchAllocation(),
        portfolioService.fetchPerformance(),
        proposalsService.fetchOrders(),
        proposalsService.fetchTrades(),
      ])
      summary.value = summaryResult.data
      openPositions.value = openResult.data
      closedPositions.value = closedResult.data
      allocation.value = allocationResult.data
      performance.value = performanceResult.data
      orders.value = ordersResult.data
      trades.value = tradesResult.data
      ui.setFallback('portfolio', summaryResult.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load portfolio.'
    } finally {
      isLoading.value = false
    }
  }

  /**
   * There's no backend endpoint yet to close a position for real (see
   * docs/IMPLEMENTATION_STATUS.md) -- this only removes it from local state so the
   * UI flow can be exercised. It intentionally does not fabricate a closed-position
   * row or touch `summary`'s real, backend-computed aggregates.
   */
  function closePosition(id: string): void {
    const ui = useUiNotificationsStore()
    const idx = openPositions.value.findIndex((p) => p.id === id)
    if (idx === -1) return
    const position = openPositions.value[idx]
    if (!position) return
    openPositions.value.splice(idx, 1)
    ui.info(`${position.symbol} position closed in this view. There's no backend endpoint yet to close positions for real, so this only updates local state.`)
  }

  return { summary, openPositions, closedPositions, allocation, performance, orders, trades, isLoading, error, loadAll, closePosition }
})
