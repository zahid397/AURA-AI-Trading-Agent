import { describe, expect, it } from 'vitest'
import {
  formatCompactCurrency,
  formatCurrency,
  formatDate,
  formatDateTime,
  formatGreek,
  formatNumber,
  formatPercent,
  formatRelativeTime,
  occSymbolLabel,
  signClass,
  signDirection,
  truncateMiddle,
} from './format'

describe('formatCurrency', () => {
  it('formats a plain number as USD with 2 decimals', () => {
    expect(formatCurrency(1234.5)).toBe('$1,234.50')
  })

  it('formats a decimal string (Laravel decimal-cast shape) the same as a number', () => {
    expect(formatCurrency('1234.50')).toBe('$1,234.50')
  })

  it('returns the fallback for null/undefined/unparseable input', () => {
    expect(formatCurrency(null)).toBe('--')
    expect(formatCurrency(undefined)).toBe('--')
    expect(formatCurrency('not-a-number')).toBe('--')
    expect(formatCurrency(null, { fallback: 'n/a' })).toBe('n/a')
  })

  it('prefixes a + sign for positive values when signed is set, but not for negative or zero', () => {
    expect(formatCurrency(45.2, { signed: true })).toBe('+$45.20')
    expect(formatCurrency(-45.2, { signed: true })).toBe('-$45.20')
    expect(formatCurrency(0, { signed: true })).toBe('$0.00')
  })

  it('never throws when maximumFractionDigits is set below the default minimum (regression: RangeError from Intl.NumberFormat)', () => {
    // This previously threw "RangeError: maximumFractionDigits value is out of range" because
    // the default minimumFractionDigits (2) was left in place while callers overrode only the
    // maximum (e.g. axis-label formatters wanting whole-dollar ticks).
    expect(() => formatCurrency(585, { maximumFractionDigits: 0 })).not.toThrow()
    expect(formatCurrency(585, { maximumFractionDigits: 0 })).toBe('$585')
  })
})

describe('formatCompactCurrency', () => {
  it('compacts large values', () => {
    expect(formatCompactCurrency(1500)).toMatch(/\$1\.5K/)
  })

  it('returns the fallback for unparseable input', () => {
    expect(formatCompactCurrency(undefined)).toBe('--')
  })
})

describe('formatPercent', () => {
  it('treats a fraction as a fraction by default (0.87 -> 87.0%)', () => {
    expect(formatPercent(0.87)).toBe('87.0%')
  })

  it('treats the value as already-a-percentage when alreadyPercent is set', () => {
    expect(formatPercent(87, { alreadyPercent: true })).toBe('87.0%')
  })

  it('respects a custom digit count', () => {
    expect(formatPercent(0.8734, { digits: 2 })).toBe('87.34%')
  })

  it('signs positive values when requested', () => {
    expect(formatPercent(0.05, { signed: true })).toBe('+5.0%')
    expect(formatPercent(-0.05, { signed: true })).toBe('-5.0%')
  })

  it('returns the fallback for unparseable input', () => {
    expect(formatPercent(null)).toBe('--')
  })
})

describe('formatNumber', () => {
  it('formats with thousands separators', () => {
    expect(formatNumber(18420)).toBe('18,420')
  })

  it('supports fixed decimal digits', () => {
    expect(formatNumber(1.5, 2)).toBe('1.50')
  })

  it('returns the fallback for unparseable input', () => {
    expect(formatNumber(undefined)).toBe('--')
  })
})

describe('formatGreek', () => {
  it('formats to 3 decimal places', () => {
    expect(formatGreek('0.612')).toBe('0.612')
    expect(formatGreek(0.6)).toBe('0.600')
  })

  it('returns the fallback for unparseable input', () => {
    expect(formatGreek(null)).toBe('--')
  })
})

describe('formatDate / formatDateTime', () => {
  it('returns the fallback for null/invalid dates', () => {
    expect(formatDate(null)).toBe('--')
    expect(formatDate('not-a-date')).toBe('--')
    expect(formatDateTime(null)).toBe('--')
  })

  it('formats a valid ISO date', () => {
    expect(formatDate('2026-08-30T12:00:00Z')).toMatch(/2026/)
  })
})

describe('formatRelativeTime', () => {
  it('describes a time in the recent past in human terms', () => {
    const fiveMinAgo = new Date(Date.now() - 5 * 60 * 1000).toISOString()
    expect(formatRelativeTime(fiveMinAgo)).toMatch(/minute/)
  })

  it('returns the fallback for null input', () => {
    expect(formatRelativeTime(null)).toBe('--')
  })
})

describe('signClass / signDirection', () => {
  it('classifies positive, negative, zero, and unparseable values', () => {
    expect(signClass(5)).toBe('text-success')
    expect(signClass(-5)).toBe('text-danger')
    expect(signClass(0)).toBe('text-slate-600')
    expect(signClass(null)).toBe('text-slate-600')

    expect(signDirection(5)).toBe('up')
    expect(signDirection(-5)).toBe('down')
    expect(signDirection(0)).toBe('flat')
    expect(signDirection(undefined)).toBe('flat')
  })
})

describe('occSymbolLabel', () => {
  it('parses a well-formed OCC option symbol into a human-readable label', () => {
    expect(occSymbolLabel('SPY260930C00580000')).toBe('SPY 09/30/26 Call $580')
  })

  it('parses a put correctly', () => {
    expect(occSymbolLabel('AAPL251121P00200000')).toBe('AAPL 11/21/25 Put $200')
  })

  it('returns the input unchanged if it does not match the OCC shape', () => {
    expect(occSymbolLabel('SPY_C570')).toBe('SPY_C570')
  })
})

describe('truncateMiddle', () => {
  it('leaves short strings untouched', () => {
    expect(truncateMiddle('short', 24)).toBe('short')
  })

  it('truncates long strings in the middle with an ellipsis', () => {
    const result = truncateMiddle('AAAAAAAAAABBBBBBBBBBCCCCCCCCCC', 14)
    expect(result).toContain('...')
    expect(result.length).toBeLessThanOrEqual(15)
  })
})
