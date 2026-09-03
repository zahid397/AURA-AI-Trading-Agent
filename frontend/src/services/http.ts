import axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import type { ApiErrorEnvelope } from '@/types/api'

/** Normalized shape every caller can rely on, regardless of what the backend actually returned. */
export class ApiError extends Error {
  code: string
  status: number | null
  requestId: string | null
  fields: Record<string, string[]> | undefined

  constructor(message: string, opts: { code: string; status: number | null; requestId: string | null; fields?: Record<string, string[]> }) {
    super(message)
    this.name = 'ApiError'
    this.code = opts.code
    this.status = opts.status
    this.requestId = opts.requestId
    this.fields = opts.fields
  }
}

function makeRequestId(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `req-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  timeout: 15000,
  headers: {
    Accept: 'application/json',
  },
})

declare module 'axios' {
  interface InternalAxiosRequestConfig {
    __retryCount?: number
  }
}

const MAX_GET_RETRIES = 2

http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  config.headers.set('X-Request-Id', makeRequestId())
  return config
})

http.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiErrorEnvelope>) => {
    const config = error.config as (InternalAxiosRequestConfig & { __retryCount?: number }) | undefined
    const isIdempotentGet = config?.method?.toLowerCase() === 'get'
    if (isIdempotentGet && config && !error.response) {
      // Network-level failure (no response at all) on a GET -- retry with backoff.
      config.__retryCount = (config.__retryCount ?? 0) + 1
      if (config.__retryCount <= MAX_GET_RETRIES) {
        const delay = 300 * 2 ** (config.__retryCount - 1)
        await new Promise((resolve) => setTimeout(resolve, delay))
        return http(config)
      }
    }
    return Promise.reject(normalizeError(error))
  },
)

function normalizeError(error: AxiosError<ApiErrorEnvelope>): ApiError {
  const status = error.response?.status ?? null
  const body = error.response?.data
  const requestId = (error.response?.headers?.['x-request-id'] as string | undefined) ?? body?.error?.request_id ?? null
  if (body?.error) {
    return new ApiError(body.error.message, { code: body.error.code, status, requestId, fields: body.error.fields })
  }
  if (error.code === 'ECONNABORTED') {
    return new ApiError('Request timed out.', { code: 'timeout', status, requestId })
  }
  if (!error.response) {
    return new ApiError('Network error -- API unreachable.', { code: 'network_error', status, requestId })
  }
  return new ApiError(error.message || 'Unexpected API error.', { code: 'unknown_error', status, requestId })
}

/** Ensures the Sanctum CSRF cookie is set before a stateful (POST/PATCH/DELETE) request from a fresh session. */
export async function ensureCsrfCookie(): Promise<void> {
  const csrfBase = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/api\/v1\/?$/, '')
  await axios.get(`${csrfBase}/sanctum/csrf-cookie`, { withCredentials: true }).catch(() => {
    // Non-fatal: some deployments proxy this differently. The actual
    // request will surface a clearer 419 error if the cookie truly is missing.
  })
}

export async function get<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.get<{ data: T }>(url, config)
  return res.data.data
}

export async function post<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.post<{ data: T }>(url, body, config)
  return res.data.data
}

export async function patch<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.patch<{ data: T }>(url, body, config)
  return res.data.data
}
