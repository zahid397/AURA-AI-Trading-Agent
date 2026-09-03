import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as backtestsService from '@/services/backtests'
import { useUiNotificationsStore } from './uiNotifications'
import type { BacktestConfig, BacktestDetail, BacktestRun, BacktestStatus } from '@/types/api'

const POLL_INTERVAL_MS = 1500
const TERMINAL_BACKTEST_STATUSES: BacktestStatus[] = ['completed', 'failed', 'cancelled']

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export const useBacktestsStore = defineStore('backtests', () => {
  const runs = ref<BacktestRun[]>([])
  const active = ref<BacktestDetail | null>(null)

  const isLoading = ref(false)
  const isStarting = ref(false)
  const isPolling = ref(false)
  const error = ref<string | null>(null)

  async function loadRuns(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const result = await backtestsService.fetchBacktests()
      runs.value = result.data
      ui.setFallback('backtests', result.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load backtests.'
    } finally {
      isLoading.value = false
    }
  }

  async function loadDetail(id: string): Promise<void> {
    isLoading.value = true
    try {
      const result = await backtestsService.fetchBacktest(id)
      active.value = result.data
      error.value = null
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load backtest result.'
    } finally {
      isLoading.value = false
    }
  }

  /**
   * StartBacktest runs on the queue worker, not inline with the HTTP
   * response, so the run is still `pending`/`running` immediately after
   * start() returns in a real deployment (it only happens to finish inline
   * under the test suite's QUEUE_CONNECTION=sync). Polls GET /backtests/{id}
   * until status leaves pending/running, the same pattern stores/agents.ts
   * already uses for agent runs.
   */
  async function pollUntilTerminal(id: string): Promise<void> {
    const ui = useUiNotificationsStore()
    isPolling.value = true
    try {
      while (active.value?.id === id && !TERMINAL_BACKTEST_STATUSES.includes(active.value.status)) {
        await wait(POLL_INTERVAL_MS)
        if (active.value?.id !== id) return
        await loadDetail(id)
      }
      if (active.value?.id === id && active.value.status === 'failed') {
        ui.error('Backtest failed to complete.')
      }
    } finally {
      isPolling.value = false
    }
  }

  async function start(config: BacktestConfig): Promise<void> {
    const ui = useUiNotificationsStore()
    isStarting.value = true
    error.value = null
    try {
      const { id } = await backtestsService.startBacktest(config)
      ui.success('Backtest started.')
      await loadDetail(id)
      await pollUntilTerminal(id)
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to start backtest.')
      error.value = err instanceof Error ? err.message : 'Failed to start backtest.'
    } finally {
      isStarting.value = false
    }
  }

  async function cancel(id: string): Promise<void> {
    const ui = useUiNotificationsStore()
    try {
      await backtestsService.cancelBacktest(id)
      ui.info('Backtest cancelled.')
      if (active.value?.id === id) active.value = { ...active.value, status: 'cancelled' }
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to cancel backtest.')
    }
  }

  return { runs, active, isLoading, isStarting, isPolling, error, loadRuns, loadDetail, start, cancel }
})
