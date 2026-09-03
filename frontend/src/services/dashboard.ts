import type { ActivityEntry, DashboardSummary, EquityCurvePoint, EquityCurveRange } from '@/types/api'
import { DASHBOARD_ACTIVITY, DASHBOARD_SUMMARY } from '@/dev/fixtures/dashboard'
import { buildEquityCurve } from '@/dev/fixtures/equityCurve'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get } from './http'

export function fetchDashboardSummary(): Promise<ServiceResult<DashboardSummary>> {
  return withFixtureFallback(
    () => get<DashboardSummary>('/dashboard/summary'),
    () => DASHBOARD_SUMMARY,
  )
}

export function fetchEquityCurve(range: EquityCurveRange): Promise<ServiceResult<EquityCurvePoint[]>> {
  return withFixtureFallback(
    () => get<EquityCurvePoint[]>('/dashboard/equity-curve', { params: { range } }),
    () => buildEquityCurve(range),
  )
}

export function fetchDashboardActivity(): Promise<ServiceResult<ActivityEntry[]>> {
  return withFixtureFallback(
    () => get<ActivityEntry[]>('/dashboard/activity'),
    () => DASHBOARD_ACTIVITY,
  )
}
