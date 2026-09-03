import type { OptionScan, OptionScanFilters, PayoffCurve, SentimentSummary, Strategy, StrategyDetail } from '@/types/api'
import { LATEST_SCAN, OPTION_OPPORTUNITIES, SENTIMENT_SUMMARIES } from '@/dev/fixtures/scanner'
import { buildPayoffCurve, buildStrategyDetail, STRATEGIES } from '@/dev/fixtures/strategies'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, post } from './http'
import { runMutation } from './mutation'

export function startOptionScan(symbols: string[], filters: OptionScanFilters): Promise<{ scan_id: string }> {
  return runMutation(
    () => post<{ scan_id: string }>('/option-scans', { symbols, filters }),
    () => ({ scan_id: LATEST_SCAN.id }),
  )
}

export function fetchOptionScan(scanId: string): Promise<ServiceResult<OptionScan>> {
  return withFixtureFallback(
    () => get<OptionScan>(`/option-scans/${scanId}`),
    () => LATEST_SCAN,
  )
}

export interface OpportunityQuery extends OptionScanFilters {
  scan_id?: string
}

export function fetchOptionOpportunities(query: OpportunityQuery = {}): Promise<ServiceResult<Strategy[]>> {
  return withFixtureFallback(
    // There is no dedicated /option-opportunities route -- the backend's
    // equivalent "scan results" view is GET /strategies (see
    // StrategyController's own doc comment: strategies.rank/.score already
    // model ranked candidates, so there's no separate opportunities table).
    // It only supports `symbol`/`agent_run_id`/`selected` filters server-side
    // (the richer direction/DTE/confidence/etc. filters here are accepted as
    // harmless extra query params but not applied -- same as before this
    // fix, when the whole list came from a route that didn't exist at all).
    () => get<Strategy[]>('/strategies', { params: query }),
    () => OPTION_OPPORTUNITIES,
  )
}

export function fetchSentimentSummaries(): Promise<ServiceResult<SentimentSummary[]>> {
  return withFixtureFallback(
    () => get<SentimentSummary[]>('/sentiment'),
    () => SENTIMENT_SUMMARIES,
  )
}

export function fetchStrategyDetail(id: string): Promise<ServiceResult<StrategyDetail>> {
  return withFixtureFallback(
    () => get<StrategyDetail>(`/strategies/${id}`),
    () => {
      const detail = buildStrategyDetail(id) ?? buildStrategyDetail(STRATEGIES[0]?.id ?? '')
      if (!detail) throw new Error('No fixture strategy available')
      return detail
    },
  )
}

export function fetchPayoffCurve(id: string): Promise<ServiceResult<PayoffCurve>> {
  return withFixtureFallback(
    () => get<PayoffCurve>(`/strategies/${id}/payoff`),
    () => {
      const curve = buildPayoffCurve(id) ?? buildPayoffCurve(STRATEGIES[0]?.id ?? '')
      if (!curve) throw new Error('No fixture payoff curve available')
      return curve
    },
  )
}
