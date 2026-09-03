import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { DEMO_CREDENTIALS } from '@/dev/fixtures/auth'
import { useAuthStore } from './auth'

// These tests run against the fixture-mode service layer (VITE_API_MODE is unset under
// Vitest, and src/services/apiMode.ts treats anything other than 'live' as 'fixture'), so
// login/logout exercise the same deterministic demo-credential logic the app ships with --
// no network mocking required.

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    sessionStorage.clear()
  })

  it('starts unauthenticated with no user', () => {
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(auth.error).toBeNull()
  })

  it('logs in successfully with the seeded demo credentials and exposes the user + settings', async () => {
    const auth = useAuthStore()
    const ok = await auth.login(DEMO_CREDENTIALS.email, DEMO_CREDENTIALS.password)

    expect(ok).toBe(true)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe(DEMO_CREDENTIALS.email)
    expect(auth.settings).not.toBeNull()
    expect(auth.error).toBeNull()
    expect(auth.isLoading).toBe(false)
  })

  it('logs in with any non-blank email and password (demo/showcase bypass, no real backend to check against)', async () => {
    const auth = useAuthStore()
    const ok = await auth.login('someone@example.com', 'literally-anything')

    expect(ok).toBe(true)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe('someone@example.com')
    expect(auth.settings).not.toBeNull()
    expect(auth.error).toBeNull()
  })

  it('rejects a blank email or password without authenticating', async () => {
    const auth = useAuthStore()
    const ok = await auth.login('', 'something')

    expect(ok).toBe(false)
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(auth.error).toBeTruthy()
  })

  it('registers a new account and exposes the user + settings, starting in approval_required mode', async () => {
    const auth = useAuthStore()
    const ok = await auth.register('Jane Trader', 'jane@example.com', 'a-strong-password', 'a-strong-password')

    expect(ok).toBe(true)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.name).toBe('Jane Trader')
    expect(auth.user?.email).toBe('jane@example.com')
    expect(auth.settings?.mode).toBe('approval_required')
    expect(auth.error).toBeNull()
  })

  it('rejects a mismatched password confirmation without authenticating', async () => {
    const auth = useAuthStore()
    const ok = await auth.register('Jane Trader', 'jane@example.com', 'a-strong-password', 'does-not-match')

    expect(ok).toBe(false)
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(auth.error).toBeTruthy()
  })

  it('clears user state on logout even though the underlying request is fire-and-forget', async () => {
    const auth = useAuthStore()
    await auth.login(DEMO_CREDENTIALS.email, DEMO_CREDENTIALS.password)
    expect(auth.isAuthenticated).toBe(true)

    await auth.logout()

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(auth.settings).toBeNull()
  })

  it('bootstrap only attempts session restore once per store instance and is a no-op with no prior session hint', async () => {
    const auth = useAuthStore()
    await auth.bootstrap()
    expect(auth.hasBootstrapped).toBe(true)
    expect(auth.isAuthenticated).toBe(false)

    // Calling again should be a safe no-op (guarded by hasBootstrapped), not re-fetch.
    await auth.bootstrap()
    expect(auth.isAuthenticated).toBe(false)
  })
})
