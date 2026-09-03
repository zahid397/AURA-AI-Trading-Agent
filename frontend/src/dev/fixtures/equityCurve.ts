import type { EquityCurvePoint, EquityCurveRange } from '@/types/api'
import { SEED_ANCHOR_DATE } from './demo'

const RANGE_DAYS: Record<EquityCurveRange, number> = {
  '1D': 1,
  '1W': 7,
  '1M': 30,
  '3M': 90,
  '1Y': 365,
  ALL: 365,
}

const RANGE_POINTS: Record<EquityCurveRange, number> = {
  '1D': 26,
  '1W': 42,
  '1M': 30,
  '3M': 60,
  '1Y': 52,
  ALL: 60,
}

/**
 * Deterministic pseudo-random walk that always lands on the exact current
 * balance (100245.69) at the final point, per docs/demo-scenario.md.
 */
export function buildEquityCurve(range: EquityCurveRange, endValue = 100245.69, startingEquity = 100000): EquityCurvePoint[] {
  const days = RANGE_DAYS[range]
  const count = RANGE_POINTS[range]
  const points: EquityCurvePoint[] = []
  const startValue = range === '1D' ? endValue - 96.4 : startingEquity
  for (let i = 0; i < count; i++) {
    const progress = i / (count - 1)
    const wiggle = Math.sin(progress * Math.PI * 5) * (startingEquity * 0.0022) * (1 - progress * 0.6)
    const drift = startValue + (endValue - startValue) * progress
    const value = i === count - 1 ? endValue : drift + wiggle
    const msAgo = (1 - progress) * days * 24 * 60 * 60 * 1000
    const t = new Date(SEED_ANCHOR_DATE.getTime() - msAgo).toISOString()
    points.push({ t, equity: value.toFixed(2) })
  }
  return points
}
