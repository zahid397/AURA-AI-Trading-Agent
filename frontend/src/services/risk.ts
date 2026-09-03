import type { RiskAlert, RiskOverview, RiskPolicy, RiskPolicyLimits } from '@/types/api'
import { isoDaysFromAnchor } from '@/dev/fixtures/demo'
import { RISK_ALERTS, RISK_OVERVIEW, RISK_POLICY, RISK_POLICY_HISTORY } from '@/dev/fixtures/risk'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, patch, post } from './http'
import { runMutation } from './mutation'

export function fetchRiskOverview(): Promise<ServiceResult<RiskOverview>> {
  return withFixtureFallback(
    () => get<RiskOverview>('/risk/overview'),
    () => RISK_OVERVIEW,
  )
}

export function fetchRiskPolicy(): Promise<ServiceResult<RiskPolicy>> {
  return withFixtureFallback(
    () => get<RiskPolicy>('/risk/policy'),
    () => RISK_POLICY,
  )
}

export function fetchRiskPolicyHistory(): Promise<ServiceResult<RiskPolicy[]>> {
  return withFixtureFallback(
    () => get<RiskPolicy[]>('/risk/policy/history'),
    () => RISK_POLICY_HISTORY,
  )
}

export function fetchRiskAlerts(): Promise<ServiceResult<RiskAlert[]>> {
  return withFixtureFallback(
    () => get<RiskAlert[]>('/risk/alerts'),
    () => RISK_ALERTS,
  )
}

export function updateRiskPolicy(limits: RiskPolicyLimits): Promise<RiskPolicy> {
  return runMutation(
    () => patch<RiskPolicy>('/risk/policy', { limits }),
    () => {
      const next: RiskPolicy = {
        ...RISK_POLICY,
        id: `risk-policy-v${RISK_POLICY.version + 1}`,
        version: RISK_POLICY.version + 1,
        limits,
        created_at: isoDaysFromAnchor(0),
      }
      RISK_POLICY_HISTORY.push(next)
      return next
    },
  )
}

export function setKillSwitch(engaged: boolean): Promise<{ engaged: boolean }> {
  return runMutation(
    () => post<{ engaged: boolean }>('/risk/kill-switch', { engaged }),
    () => {
      RISK_OVERVIEW.kill_switch_engaged = engaged
      return { engaged }
    },
  )
}
