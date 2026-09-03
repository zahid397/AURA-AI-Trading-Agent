/**
 * Centralized formatters. The backend sends money/decimal columns as
 * strings (Laravel decimal cast -> JSON string) so every financial figure
 * in the UI must go through here rather than doing ad-hoc float math or
 * template-literal interpolation, which produces artifacts like
 * `182.34000000001`. `Intl.NumberFormat` rounds correctly for the display
 * precision we ask for, so parsing to `number` purely for *display*
 * (never for further arithmetic) is safe at the magnitudes this app deals
 * with.
 */

const DEFAULT_LOCALE = 'en-US'
const DEFAULT_CURRENCY = 'USD'

function toFiniteNumber(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = typeof value === 'number' ? value : Number.parseFloat(value)
  return Number.isFinite(n) ? n : null
}

export interface CurrencyOptions {
  /** Prefix a `+` for positive values (P&L style figures). */
  signed?: boolean
  /** Fall back text when the value can't be parsed. */
  fallback?: string
  minimumFractionDigits?: number
  maximumFractionDigits?: number
}

export function formatCurrency(value: string | number | null | undefined, opts: CurrencyOptions = {}): string {
  const n = toFiniteNumber(value)
  if (n === null) return opts.fallback ?? '--'
  // `Intl.NumberFormat` throws a RangeError if minimumFractionDigits ends up greater than
  // maximumFractionDigits -- guard against that when a caller only overrides one of the two
  // (e.g. `{ maximumFractionDigits: 0 }` against the default minimum of 2).
  const maximumFractionDigits = opts.maximumFractionDigits ?? 2
  const minimumFractionDigits = Math.min(opts.minimumFractionDigits ?? 2, maximumFractionDigits)
  const formatter = new Intl.NumberFormat(DEFAULT_LOCALE, {
    style: 'currency',
    currency: DEFAULT_CURRENCY,
    minimumFractionDigits,
    maximumFractionDigits,
  })
  const formatted = formatter.format(n)
  if (opts.signed && n > 0) return `+${formatted}`
  return formatted
}

export function formatCompactCurrency(value: string | number | null | undefined, fallback = '--'): string {
  const n = toFiniteNumber(value)
  if (n === null) return fallback
  return new Intl.NumberFormat(DEFAULT_LOCALE, {
    style: 'currency',
    currency: DEFAULT_CURRENCY,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(n)
}

export interface PercentOptions {
  /** Treat the input as already-a-percentage number (e.g. 87 -> "87%") rather than a fraction (0.87 -> "87%"). */
  alreadyPercent?: boolean
  signed?: boolean
  fallback?: string
  digits?: number
}

export function formatPercent(value: string | number | null | undefined, opts: PercentOptions = {}): string {
  const n = toFiniteNumber(value)
  if (n === null) return opts.fallback ?? '--'
  const pct = opts.alreadyPercent ? n : n * 100
  const digits = opts.digits ?? 1
  const formatted = `${pct.toFixed(digits)}%`
  if (opts.signed && pct > 0) return `+${formatted}`
  return formatted
}

export function formatNumber(value: string | number | null | undefined, digits = 0, fallback = '--'): string {
  const n = toFiniteNumber(value)
  if (n === null) return fallback
  return new Intl.NumberFormat(DEFAULT_LOCALE, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(n)
}

export function formatGreek(value: string | number | null | undefined, fallback = '--'): string {
  const n = toFiniteNumber(value)
  if (n === null) return fallback
  return n.toFixed(3)
}

/** ISO datetime -> "Aug 30, 2026, 4:12 PM" in the browser's local zone (UTC source per db-schema). */
export function formatDateTime(value: string | null | undefined, fallback = '--'): string {
  if (!value) return fallback
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return fallback
  return new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d)
}

export function formatDate(value: string | null | undefined, fallback = '--'): string {
  if (!value) return fallback
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return fallback
  return new Intl.DateTimeFormat(DEFAULT_LOCALE, { dateStyle: 'medium' }).format(d)
}

export function formatTime(value: string | null | undefined, fallback = '--'): string {
  if (!value) return fallback
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return fallback
  return new Intl.DateTimeFormat(DEFAULT_LOCALE, { timeStyle: 'short' }).format(d)
}

export function formatRelativeTime(value: string | null | undefined, fallback = '--'): string {
  if (!value) return fallback
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return fallback
  const diffMs = d.getTime() - Date.now()
  const diffSec = Math.round(diffMs / 1000)
  const rtf = new Intl.RelativeTimeFormat(DEFAULT_LOCALE, { numeric: 'auto' })
  const abs = Math.abs(diffSec)
  if (abs < 60) return rtf.format(diffSec, 'second')
  const diffMin = Math.round(diffSec / 60)
  if (Math.abs(diffMin) < 60) return rtf.format(diffMin, 'minute')
  const diffHr = Math.round(diffMin / 60)
  if (Math.abs(diffHr) < 24) return rtf.format(diffHr, 'hour')
  const diffDay = Math.round(diffHr / 24)
  return rtf.format(diffDay, 'day')
}

/** Sign class helper for pairing color with icon/text, never color alone. */
export function signClass(value: string | number | null | undefined): 'text-success' | 'text-danger' | 'text-slate-600' {
  const n = toFiniteNumber(value)
  if (n === null || n === 0) return 'text-slate-600'
  return n > 0 ? 'text-success' : 'text-danger'
}

export function signDirection(value: string | number | null | undefined): 'up' | 'down' | 'flat' {
  const n = toFiniteNumber(value)
  if (n === null || n === 0) return 'flat'
  return n > 0 ? 'up' : 'down'
}

export function occSymbolLabel(occ: string): string {
  // OCC format: ROOT + YYMMDD + C/P + strike*1000 (8 digits) e.g. SPY260930C00580000
  const m = /^([A-Z]+)(\d{6})([CP])(\d{8})$/.exec(occ)
  if (!m) return occ
  const [, root, ymd, cp, strikeRaw] = m as unknown as [string, string, string, string, string]
  const yy = ymd.slice(0, 2)
  const mm = ymd.slice(2, 4)
  const dd = ymd.slice(4, 6)
  const strike = Number.parseInt(strikeRaw, 10) / 1000
  return `${root} ${mm}/${dd}/${yy} ${cp === 'C' ? 'Call' : 'Put'} $${strike}`
}

export function truncateMiddle(value: string, max = 24): string {
  if (value.length <= max) return value
  const half = Math.floor((max - 3) / 2)
  return `${value.slice(0, half)}...${value.slice(value.length - half)}`
}
