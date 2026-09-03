import type { BacktestConfig, BacktestDetail, BacktestRun } from '@/types/api'
import { BACKTEST_DETAIL, BACKTEST_RUNS } from '@/dev/fixtures/backtests'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, post } from './http'
import { runMutation } from './mutation'

export function fetchBacktests(): Promise<ServiceResult<BacktestRun[]>> {
  return withFixtureFallback(
    () => get<BacktestRun[]>('/backtests'),
    () => BACKTEST_RUNS,
  )
}

export function fetchBacktest(id: string): Promise<ServiceResult<BacktestDetail>> {
  return withFixtureFallback(
    () => get<BacktestDetail>(`/backtests/${id}`),
    () => BACKTEST_DETAIL,
  )
}

export function startBacktest(config: BacktestConfig): Promise<{ id: string }> {
  return runMutation(
    () => post<{ id: string }>('/backtests', config),
    () => ({ id: BACKTEST_DETAIL.id }),
  )
}

export function cancelBacktest(id: string): Promise<void> {
  return runMutation(
    () => post<void>(`/backtests/${id}/cancel`),
    () => undefined,
  )
}
