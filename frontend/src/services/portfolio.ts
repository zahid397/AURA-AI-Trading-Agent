import type { PerformanceReport, PortfolioAllocation, PortfolioSummary, Position, PositionStatus } from '@/types/api'
import { ALL_POSITIONS, CLOSED_POSITIONS, OPEN_POSITIONS, PERFORMANCE_REPORT, PORTFOLIO_ALLOCATION, PORTFOLIO_SUMMARY } from '@/dev/fixtures/portfolio'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get } from './http'

export function fetchPortfolioSummary(): Promise<ServiceResult<PortfolioSummary>> {
  return withFixtureFallback(
    () => get<PortfolioSummary>('/portfolio/summary'),
    () => PORTFOLIO_SUMMARY,
  )
}

export function fetchPositions(status?: PositionStatus): Promise<ServiceResult<Position[]>> {
  return withFixtureFallback(
    () => get<Position[]>('/portfolio/positions', { params: status ? { status } : {} }),
    () => (status === 'open' ? OPEN_POSITIONS : status === 'closed' ? CLOSED_POSITIONS : ALL_POSITIONS),
  )
}

export function fetchAllocation(): Promise<ServiceResult<PortfolioAllocation>> {
  return withFixtureFallback(
    () => get<PortfolioAllocation>('/portfolio/allocation'),
    () => PORTFOLIO_ALLOCATION,
  )
}

export function fetchPerformance(): Promise<ServiceResult<PerformanceReport>> {
  return withFixtureFallback(
    () => get<PerformanceReport>('/portfolio/performance'),
    () => PERFORMANCE_REPORT,
  )
}
