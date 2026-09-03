import type { AlpacaConnection, AppSettings, AutonomyMode, MarketClock, SystemHealth } from '@/types/api'
import { APP_SETTINGS, buildMarketClock, SYSTEM_HEALTH } from '@/dev/fixtures/settings'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, patch, post } from './http'
import { runMutation } from './mutation'

/**
 * Actual shape of `GET /settings` (see backend `SettingsController::show()`
 * / `UserSettingsResource`) -- `mode`/`confidence_threshold`/`notification_prefs`
 * nested under a `settings` key, Alpaca info under a sibling `alpaca_connection`
 * key, and no `general`/`ai_provider`/`risk`/`security` sections at all (the
 * backend doesn't source those yet). `PATCH /settings` accepts and returns
 * only the flat `UserSettingsResource` fields, with neither wrapper. Adapters
 * below reshape both into this app's flat `AppSettings` DTO -- and back for
 * the write side -- so the store and pages can keep using one shape without
 * needing to know about the mismatch.
 */
interface RawUserSettings {
  mode: AutonomyMode
  confidence_threshold: string
  notification_prefs: AppSettings['notifications']
}

interface SettingsShowResponse {
  settings: RawUserSettings
  alpaca_connection: AlpacaConnection | null
}

/** Neutral placeholders for sections the backend doesn't return yet -- mirrors the defaults `SettingsPage.vue` already falls back to. */
const PLACEHOLDER_APP_SETTINGS: AppSettings = {
  general: { timezone: 'America/New_York', currency_display: 'USD', default_page: '/dashboard' },
  alpaca: { environment: 'paper', status: 'not_configured', account_id: null, api_key_last4: null, capabilities: [], last_synced_at: null },
  ai_provider: { provider: 'anthropic', model: 'claude-sonnet', configured: false, key_fingerprint: null },
  risk: { active_policy_version: 1 },
  agents: { autonomy_mode: 'approval_required', confidence_threshold: '0.55' },
  notifications: { email_on_execution: true, email_on_rejection: true, email_on_kill_switch: true, in_app: true },
  security: { two_factor_enabled: false, active_sessions: 1 },
}

function toAppSettings(raw: RawUserSettings, alpaca: AlpacaConnection | null, base: AppSettings): AppSettings {
  return {
    ...base,
    alpaca: alpaca ?? base.alpaca,
    agents: { autonomy_mode: raw.mode, confidence_threshold: raw.confidence_threshold },
    notifications: raw.notification_prefs,
  }
}

export function fetchSettings(): Promise<ServiceResult<AppSettings>> {
  return withFixtureFallback(
    async () => {
      const raw = await get<SettingsShowResponse>('/settings')
      return toAppSettings(raw.settings, raw.alpaca_connection, PLACEHOLDER_APP_SETTINGS)
    },
    () => APP_SETTINGS,
  )
}

export function updateSettings(partial: Partial<AppSettings>, current?: AppSettings | null): Promise<AppSettings> {
  const body: Partial<RawUserSettings> = {}
  if (partial.agents?.autonomy_mode) body.mode = partial.agents.autonomy_mode
  if (partial.agents?.confidence_threshold) body.confidence_threshold = partial.agents.confidence_threshold
  if (partial.notifications) body.notification_prefs = partial.notifications

  return runMutation(
    async () => {
      const raw = await patch<RawUserSettings>('/settings', body)
      const base: AppSettings = { ...(current ?? PLACEHOLDER_APP_SETTINGS), ...partial }
      return toAppSettings(raw, null, base)
    },
    () => {
      Object.assign(APP_SETTINGS, partial)
      return { ...APP_SETTINGS }
    },
  )
}

export function testAlpacaConnection(): Promise<{ status: string; capabilities: string[] }> {
  return runMutation(
    () => post<{ status: string; capabilities: string[] }>('/settings/alpaca/test'),
    () => ({ status: APP_SETTINGS.alpaca.status, capabilities: APP_SETTINGS.alpaca.capabilities }),
  )
}

export function testAiProviderConnection(): Promise<{ status: string }> {
  return runMutation(
    () => post<{ status: string }>('/settings/ai-provider/test'),
    () => ({ status: APP_SETTINGS.ai_provider.configured ? 'connected' : 'not_configured' }),
  )
}

export function fetchSystemHealth(): Promise<ServiceResult<SystemHealth>> {
  return withFixtureFallback(
    () => get<SystemHealth>('/system/health'),
    () => SYSTEM_HEALTH,
  )
}

export function fetchMarketClock(): Promise<ServiceResult<MarketClock>> {
  return withFixtureFallback(
    () => get<MarketClock>('/market/clock'),
    () => buildMarketClock(),
  )
}
