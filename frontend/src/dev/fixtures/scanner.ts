import type { OptionScan, SentimentSummary } from '@/types/api'
import { AGENT_RUN_ID } from './agents'
import { isoDaysFromAnchor } from './demo'
import { STRATEGIES } from './strategies'

export const LATEST_SCAN: OptionScan = {
  id: 'scan-2026-08-30-0001',
  agent_run_id: AGENT_RUN_ID,
  status: 'completed',
  symbols: ['SPY', 'NVDA', 'TSLA'],
  filters: { direction: 'any', strategy: 'any' },
  progress_pct: 100,
  created_at: isoDaysFromAnchor(0),
  completed_at: isoDaysFromAnchor(0),
  result_count: STRATEGIES.length,
}

export const SENTIMENT_SUMMARIES: SentimentSummary[] = [
  { symbol: 'SPY', sentiment_score: 0.62, iv_percentile: 38, trend: 'bullish' },
  { symbol: 'NVDA', sentiment_score: 0.1, iv_percentile: 81, trend: 'neutral' },
  { symbol: 'TSLA', sentiment_score: 0.44, iv_percentile: 54, trend: 'bullish' },
]

// The scanner's ranked opportunity grid reuses the same `Strategy` rows the
// options-strategy agent produced this run -- `GET /option-opportunities`
// per docs/api-routes.md returns ranked `strategies` rows.
export const OPTION_OPPORTUNITIES = STRATEGIES
