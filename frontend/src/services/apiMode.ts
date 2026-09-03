/**
 * The fixture/live seam. `VITE_API_MODE=fixture` (the `.env.development`
 * default) resolves every domain service call from `src/dev/fixtures`
 * with a simulated network delay -- no backend required to work on the UI.
 * `VITE_API_MODE=live` (the `.env.production` default) sends real Axios
 * requests. In live mode, if a request fails for any reason (backend down,
 * network error, non-2xx), the service layer catches it and falls back to
 * the same fixture data so a page never renders blank -- see the `*Source`
 * tracking in `src/stores/uiNotifications.ts`.
 */
export const API_MODE: 'fixture' | 'live' = import.meta.env.VITE_API_MODE === 'live' ? 'live' : 'fixture'

const FIXTURE_DELAY_MS = Number.parseInt(import.meta.env.VITE_FIXTURE_DELAY_MS ?? '350', 10) || 350

export function simulateDelay(ms = FIXTURE_DELAY_MS): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** Result wrapper every domain service returns so stores can surface data provenance. */
export interface ServiceResult<T> {
  data: T
  /** 'live' = real API responded. 'fixture' = fixture data, either because API_MODE=fixture by design, or as a failure fallback. */
  source: 'live' | 'fixture'
  /** Set only when source==='fixture' because a live request actually failed (not when fixture mode is the deliberate setting). */
  fallbackReason?: string
}

export function liveResult<T>(data: T): ServiceResult<T> {
  return { data, source: 'live' }
}

export function fixtureResult<T>(data: T, fallbackReason?: string): ServiceResult<T> {
  const result: ServiceResult<T> = { data, source: 'fixture' }
  if (fallbackReason) result.fallbackReason = fallbackReason
  return result
}

/**
 * Runs `liveCall` when API_MODE is 'live'; on any failure (or when
 * API_MODE is 'fixture' outright) resolves `fixtureFactory` after a
 * simulated delay instead of throwing. `fixtureFactory` is a thunk so
 * fixture construction (which can involve `new Date()`-anchored data) only
 * happens when actually needed.
 */
export async function withFixtureFallback<T>(liveCall: () => Promise<T>, fixtureFactory: () => T): Promise<ServiceResult<T>> {
  if (API_MODE === 'fixture') {
    await simulateDelay()
    return fixtureResult(fixtureFactory())
  }
  try {
    const data = await liveCall()
    return liveResult(data)
  } catch (err) {
    await simulateDelay(Math.min(FIXTURE_DELAY_MS, 200))
    const reason = describeError(err)
    return fixtureResult(fixtureFactory(), reason)
  }
}

function describeError(err: unknown): string {
  if (err && typeof err === 'object' && 'message' in err && typeof (err as { message?: unknown }).message === 'string') {
    return (err as { message: string }).message
  }
  return 'API unreachable'
}
