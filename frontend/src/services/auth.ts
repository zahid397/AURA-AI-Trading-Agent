import type { MeResponse, User, UserSettings } from '@/types/api'
import { ME_RESPONSE } from '@/dev/fixtures/auth'
import { API_MODE, simulateDelay } from './apiMode'
import { ApiError, ensureCsrfCookie, get, http, post } from './http'

/**
 * Actual shape of `GET /me`, `POST /auth/login`, and `POST /auth/register`
 * (see backend `UserResource::toArray()`) -- the user's own fields flat at
 * the top level with `settings` nested, NOT wrapped in a `{ user, settings }`
 * envelope. Reshaped into this app's internal `MeResponse` DTO below so the
 * rest of the app (stores, components) can keep using the more convenient
 * `{ user, settings }` shape without every consumer needing to know that.
 */
type MeApiResponse = User & { settings: UserSettings }

function toMeResponse(raw: MeApiResponse): MeResponse {
  const { settings, ...user } = raw
  return { user, settings }
}

export async function login(email: string, password: string): Promise<MeResponse> {
  if (API_MODE === 'fixture') {
    await simulateDelay()
    // Demo/showcase build: any non-blank email+password logs in successfully
    // (no real backend to check credentials against -- see VITE_API_MODE).
    // The exact seeded DEMO_CREDENTIALS still work too, they're just no
    // longer required.
    if (!email.trim() || !password) {
      throw new ApiError('Invalid email or password.', { code: 'invalid_credentials', status: 401, requestId: null })
    }
    return { ...ME_RESPONSE, user: { ...ME_RESPONSE.user, email: email.trim() } }
  }
  await ensureCsrfCookie()
  await http.post('/auth/login', { email, password })
  return toMeResponse(await get<MeApiResponse>('/me'))
}

export async function register(name: string, email: string, password: string, passwordConfirmation: string): Promise<MeResponse> {
  if (API_MODE === 'fixture') {
    await simulateDelay()
    if (password !== passwordConfirmation) {
      throw new ApiError('The password confirmation does not match.', {
        code: 'validation_error',
        status: 422,
        requestId: null,
        fields: { password_confirmation: ['The password confirmation does not match.'] },
      })
    }
    // Fixture mode has no real backend to persist to -- simulate a freshly
    // created account using the seeded demo settings as a starting point.
    return { user: { ...ME_RESPONSE.user, name, email }, settings: ME_RESPONSE.settings }
  }
  await ensureCsrfCookie()
  const raw = await post<MeApiResponse>('/auth/register', {
    name,
    email,
    password,
    password_confirmation: passwordConfirmation,
  })
  return toMeResponse(raw)
}

export async function logout(): Promise<void> {
  if (API_MODE === 'fixture') {
    await simulateDelay(150)
    return
  }
  await http.post('/auth/logout')
}

export async function fetchMe(): Promise<MeResponse> {
  if (API_MODE === 'fixture') {
    await simulateDelay(150)
    return ME_RESPONSE
  }
  return toMeResponse(await get<MeApiResponse>('/me'))
}
