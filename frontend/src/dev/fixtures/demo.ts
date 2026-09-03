/**
 * Single source of truth for fixture demo data, mirroring
 * docs/demo-scenario.md exactly. Every other fixture module imports the
 * anchor date and core numbers from here so nothing drifts.
 */

// Computed once per session load so contract dates are always relative to
// "now" and never render as already-expired, per demo-scenario.md.
export const SEED_ANCHOR_DATE = new Date()

export function daysFromAnchor(n: number): Date {
  const d = new Date(SEED_ANCHOR_DATE)
  d.setDate(d.getDate() + n)
  return d
}

export function isoDaysFromAnchor(n: number): string {
  return daysFromAnchor(n).toISOString()
}

export function isoDateDaysFromAnchor(n: number): string {
  return daysFromAnchor(n).toISOString().slice(0, 10)
}

export const DEMO_USER = {
  id: 'user-demo-001',
  name: 'Demo Trader',
  // Must match the real seeded account (backend/database/seeders/DemoSeeder.php)
  // exactly -- this value also fills the "Fill demo credentials" button on the
  // live login page, not just the offline fixture.
  email: 'demo@aura.trading',
  timezone: 'America/New_York',
  currency_display: 'USD',
  default_page: '/dashboard',
} as const

export const DEMO_PASSWORD = 'password'

export const DEMO_NUMBERS = {
  startingEquity: '100000.00',
  balance: '100245.69',
  totalPl: '245.69',
  unrealizedPl: '182.34',
  realizedPl: '63.35',
  buyingPower: '78120.45',
  riskLevel: 'LOW' as const,
}
