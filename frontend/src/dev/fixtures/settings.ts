import type { AlpacaConnection, AppSettings, MarketClock, SystemHealth } from '@/types/api'
import { DEMO_USER, isoDaysFromAnchor } from './demo'

export const ALPACA_CONNECTION: AlpacaConnection = {
  environment: 'paper',
  status: 'connected',
  account_id: 'PA2FAKE0001',
  api_key_last4: 'K3F9',
  capabilities: ['account_lookup', 'option_chain', 'place_option_order', 'positions', 'orders'],
  last_synced_at: isoDaysFromAnchor(-0.02),
}

export const APP_SETTINGS: AppSettings = {
  general: {
    timezone: DEMO_USER.timezone,
    currency_display: DEMO_USER.currency_display,
    default_page: DEMO_USER.default_page,
  },
  alpaca: ALPACA_CONNECTION,
  ai_provider: {
    provider: 'anthropic',
    model: 'claude-sonnet',
    configured: true,
    key_fingerprint: 'sk-ant-...9f2a',
  },
  risk: {
    active_policy_version: 1,
  },
  agents: {
    autonomy_mode: 'approval_required',
    confidence_threshold: '0.55',
  },
  notifications: {
    email_on_execution: true,
    email_on_rejection: true,
    email_on_kill_switch: true,
    in_app: true,
  },
  security: {
    two_factor_enabled: false,
    active_sessions: 1,
  },
}

export const SYSTEM_HEALTH: SystemHealth = {
  overall: 'operational',
  components: [
    { key: 'laravel', label: 'Laravel API', status: 'operational', detail: 'All routes responding.', latency_ms: 42, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'queue', label: 'Queue worker', status: 'operational', detail: '0 jobs pending, 0 failed.', latency_ms: null, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'scheduler', label: 'Scheduler', status: 'operational', detail: 'Last tick on time.', latency_ms: null, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'postgres', label: 'PostgreSQL', status: 'operational', detail: 'Connected, 4ms round trip.', latency_ms: 4, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'redis', label: 'Redis', status: 'operational', detail: 'Connected.', latency_ms: 2, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'ai_engine', label: 'FastAPI AI Engine', status: 'operational', detail: 'LangGraph orchestrator reachable.', latency_ms: 118, checked_at: isoDaysFromAnchor(-0.001) },
    { key: 'alpaca_api', label: 'Alpaca Trading API', status: 'operational', detail: 'Paper account authenticated.', latency_ms: 210, checked_at: isoDaysFromAnchor(-0.02) },
    { key: 'alpaca_mcp', label: 'Alpaca MCP server', status: 'not_configured', detail: 'MCP sidecar not started in this environment.', latency_ms: null, checked_at: isoDaysFromAnchor(-0.02) },
    { key: 'alpaca_cli', label: 'Alpaca CLI diagnostics', status: 'operational', detail: 'Smoke check passed.', latency_ms: 340, checked_at: isoDaysFromAnchor(-0.5) },
    { key: 'realtime', label: 'Realtime (Reverb/WebSocket)', status: 'degraded', detail: 'Reconnecting -- falling back to polling.', latency_ms: null, checked_at: isoDaysFromAnchor(-0.001) },
  ],
}

export function buildMarketClock(): MarketClock {
  const now = new Date()
  const day = now.getUTCDay()
  const hour = now.getUTCHours()
  // Rough NYSE session check (13:30-20:00 UTC, Mon-Fri) -- fixture-only approximation.
  const isOpen = day >= 1 && day <= 5 && hour >= 13 && hour < 20
  return {
    is_open: isOpen,
    next_open: isoDaysFromAnchor(isOpen ? 1 : 0.2),
    next_close: isoDaysFromAnchor(isOpen ? 0.2 : 1.2),
    server_time: now.toISOString(),
  }
}
