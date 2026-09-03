<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  Bell,
  CircleCheck,
  CircleX,
  Cog,
  KeyRound,
  Loader2,
  Server,
  ShieldAlert,
  Sliders,
  Zap,
} from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DataSourceBanner from '@/components/common/DataSourceBanner.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import * as settingsService from '@/services/settings'
import { useSettingsStore } from '@/stores/settings'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatDateTime, formatNumber } from '@/utils/format'
import type { AppSettings, AutonomyMode, SystemComponentStatus } from '@/types/api'

const settings = useSettingsStore()
const ui = useUiNotificationsStore()

onMounted(() => void settings.loadAll())

type Section = 'general' | 'alpaca' | 'ai' | 'risk' | 'agents' | 'notifications' | 'security' | 'health'
const SECTIONS: { key: Section; label: string; icon: typeof Cog }[] = [
  { key: 'general', label: 'General', icon: Cog },
  { key: 'alpaca', label: 'Alpaca Connection', icon: KeyRound },
  { key: 'ai', label: 'AI Provider', icon: Sliders },
  { key: 'risk', label: 'Risk Settings', icon: ShieldAlert },
  { key: 'agents', label: 'Agent Controls', icon: Zap },
  { key: 'notifications', label: 'Notifications', icon: Bell },
  { key: 'security', label: 'Security', icon: KeyRound },
  { key: 'health', label: 'System Health', icon: Server },
]
const activeSection = ref<Section>('general')

const form = reactive<AppSettings>({
  general: { timezone: 'America/New_York', currency_display: 'USD', default_page: '/dashboard' },
  alpaca: { environment: 'paper', status: 'not_configured', account_id: null, api_key_last4: null, capabilities: [], last_synced_at: null },
  ai_provider: { provider: 'anthropic', model: 'claude-sonnet', configured: false, key_fingerprint: null },
  risk: { active_policy_version: 1 },
  agents: { autonomy_mode: 'approval_required', confidence_threshold: '0.55' },
  notifications: { email_on_execution: true, email_on_rejection: true, email_on_kill_switch: true, in_app: true },
  security: { two_factor_enabled: false, active_sessions: 1 },
})

watch(
  () => settings.settings,
  (s) => {
    // `s` is a Pinia-reactive object (a Proxy) -- `structuredClone` can throw a DataCloneError
    // on some reactive proxies, so round-trip through JSON instead, which is safe here since
    // `AppSettings` is plain JSON-serializable data with no functions/dates/circular refs.
    if (s) Object.assign(form, JSON.parse(JSON.stringify(s)))
  },
  { immediate: true },
)

const isTestingAlpaca = ref(false)
const isTestingAi = ref(false)

async function testAlpaca(): Promise<void> {
  isTestingAlpaca.value = true
  try {
    const result = await settingsService.testAlpacaConnection()
    ui.success(`Alpaca connection: ${result.status}.`)
  } catch (err) {
    ui.error(err instanceof Error ? err.message : 'Alpaca connection test failed.')
  } finally {
    isTestingAlpaca.value = false
  }
}
async function testAi(): Promise<void> {
  isTestingAi.value = true
  try {
    const result = await settingsService.testAiProviderConnection()
    ui.success(`AI provider status: ${result.status}.`)
  } catch (err) {
    ui.error(err instanceof Error ? err.message : 'AI provider test failed.')
  } finally {
    isTestingAi.value = false
  }
}

async function saveSection(section: Section): Promise<void> {
  const map: Partial<Record<Section, Partial<AppSettings>>> = {
    general: { general: form.general },
    notifications: { notifications: form.notifications },
    security: { security: form.security },
  }
  const partial = map[section]
  if (partial) await settings.save(partial)
}

// Autonomy mode change requires confirmation.
const pendingMode = ref<AutonomyMode | null>(null)
const modeDialogOpen = ref(false)
function onModeSelect(e: Event): void {
  const value = (e.target as HTMLSelectElement).value as AutonomyMode
  pendingMode.value = value
  modeDialogOpen.value = true
}
async function confirmModeChange(): Promise<void> {
  if (!pendingMode.value) return
  form.agents.autonomy_mode = pendingMode.value
  await settings.save({ agents: form.agents })
  modeDialogOpen.value = false
  pendingMode.value = null
}
function cancelModeChange(): void {
  modeDialogOpen.value = false
  pendingMode.value = null
}

const checklist = reactive([
  { label: 'Create a fresh Alpaca paper trading account for judging', done: false },
  { label: 'Generate a new paper API key/secret pair', done: false },
  { label: 'Confirm TRADING_ENV is set to paper (never live)', done: true },
  { label: 'Verify zero prior positions/orders on the account', done: false },
  { label: 'Confirm options trading level is enabled on the paper account', done: false },
])

function healthTone(status: SystemComponentStatus): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'operational') return 'success'
  if (status === 'degraded') return 'warning'
  if (status === 'down') return 'danger'
  return 'neutral'
}

const overallHealthy = computed(() => settings.systemHealth?.overall === 'operational')
</script>

<template>
  <div class="space-y-5">
    <DataSourceBanner :reason="ui.fallbackDomains['settings'] ?? null" />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[220px_1fr]">
      <nav class="space-y-0.5 rounded-card border border-border bg-surface p-2 shadow-sm lg:sticky lg:top-[76px] lg:h-fit">
        <button
          v-for="section in SECTIONS"
          :key="section.key"
          type="button"
          class="flex w-full items-center gap-2 rounded-control px-2.5 py-2 text-left text-sm font-medium transition-colors"
          :class="activeSection === section.key ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-canvas'"
          @click="activeSection = section.key"
        >
          <component :is="section.icon" :size="15" />
          {{ section.label }}
        </button>
      </nav>

      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <div v-if="settings.isLoading && !settings.settings" class="space-y-2">
          <Skeleton v-for="i in 4" :key="i" height="2rem" />
        </div>
        <ErrorRetry v-else-if="settings.error && !settings.settings" :message="settings.error" @retry="settings.loadAll" />

        <template v-else>
          <!-- General -->
          <section v-if="activeSection === 'general'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">General</h2>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Timezone</label>
              <input v-model="form.general.timezone" type="text" class="w-full max-w-sm rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Currency display</label>
              <input v-model="form.general.currency_display" type="text" class="w-full max-w-sm rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Default landing page</label>
              <input v-model="form.general.default_page" type="text" class="w-full max-w-sm rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
            </div>
            <button type="button" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="settings.isSaving" @click="saveSection('general')">
              {{ settings.isSaving ? 'Saving…' : 'Save changes' }}
            </button>
          </section>

          <!-- Alpaca -->
          <section v-else-if="activeSection === 'alpaca'" class="space-y-4">
            <h2 class="text-sm font-semibold text-ink-950">Alpaca Connection</h2>
            <div class="flex items-center gap-2">
              <Badge tone="info">paper only</Badge>
              <Badge :tone="form.alpaca.status === 'connected' ? 'success' : form.alpaca.status === 'error' ? 'danger' : 'neutral'">{{ form.alpaca.status }}</Badge>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-xs text-slate-600">Account ID</dt><dd class="tabular-nums font-medium text-ink-950">{{ form.alpaca.account_id ?? '--' }}</dd></div>
              <div><dt class="text-xs text-slate-600">API key</dt><dd class="tabular-nums font-medium text-ink-950">•••• {{ form.alpaca.api_key_last4 ?? '----' }}</dd></div>
              <div><dt class="text-xs text-slate-600">Last synced</dt><dd class="font-medium text-ink-950">{{ formatDateTime(form.alpaca.last_synced_at) }}</dd></div>
            </dl>
            <div>
              <p class="mb-1 text-xs font-medium text-slate-600">Capabilities</p>
              <div class="flex flex-wrap gap-1"><Badge v-for="c in form.alpaca.capabilities" :key="c" tone="neutral">{{ c.replaceAll('_', ' ') }}</Badge></div>
            </div>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas disabled:opacity-60" :disabled="isTestingAlpaca" @click="testAlpaca">
              <Loader2 v-if="isTestingAlpaca" :size="14" class="animate-spin" />
              Test connection
            </button>
            <div class="border-t border-border pt-3">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Fresh paper account checklist (for judging)</p>
              <ul class="space-y-1.5">
                <li v-for="item in checklist" :key="item.label" class="flex items-center gap-2 text-sm">
                  <input :id="item.label" v-model="item.done" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
                  <label :for="item.label" :class="item.done ? 'text-slate-600 line-through' : 'text-ink-950'">{{ item.label }}</label>
                </li>
              </ul>
            </div>
          </section>

          <!-- AI provider -->
          <section v-else-if="activeSection === 'ai'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">AI Provider</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-xs text-slate-600">Provider</dt><dd class="font-medium capitalize text-ink-950">{{ form.ai_provider.provider }}</dd></div>
              <div><dt class="text-xs text-slate-600">Model</dt><dd class="font-medium text-ink-950">{{ form.ai_provider.model }}</dd></div>
              <div><dt class="text-xs text-slate-600">Configured</dt><dd><Badge :tone="form.ai_provider.configured ? 'success' : 'warning'">{{ form.ai_provider.configured ? 'yes' : 'no' }}</Badge></dd></div>
              <div><dt class="text-xs text-slate-600">Key fingerprint</dt><dd class="tabular-nums font-medium text-ink-950">{{ form.ai_provider.key_fingerprint ?? '--' }}</dd></div>
            </dl>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-950 hover:bg-canvas disabled:opacity-60" :disabled="isTestingAi" @click="testAi">
              <Loader2 v-if="isTestingAi" :size="14" class="animate-spin" />
              Test AI provider
            </button>
          </section>

          <!-- Risk -->
          <section v-else-if="activeSection === 'risk'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">Risk Settings</h2>
            <p class="text-sm text-slate-600">Active risk policy version: <span class="font-semibold text-ink-950">v{{ form.risk.active_policy_version }}</span></p>
            <router-link to="/risk-management" class="inline-block text-sm font-medium text-brand-700 hover:underline">Edit limits &amp; view version history on the Risk Management page →</router-link>
          </section>

          <!-- Agent controls -->
          <section v-else-if="activeSection === 'agents'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">Agent Controls</h2>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Autonomy mode</label>
              <select :value="form.agents.autonomy_mode" class="w-full max-w-sm rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" @change="onModeSelect">
                <option value="demo">Demo (no live agent activity)</option>
                <option value="observe">Observe (analysis only, no trades)</option>
                <option value="approval_required">Approval required</option>
                <option value="paper_autonomous">Paper autonomous</option>
              </select>
              <p class="mt-1 text-xs text-slate-600">Changing autonomy mode requires confirmation and is audited.</p>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Confidence threshold</label>
              <input v-model.number="form.agents.confidence_threshold" type="number" step="0.05" min="0" max="1" class="w-full max-w-xs rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm" />
            </div>
            <button type="button" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="settings.isSaving" @click="settings.save({ agents: form.agents })">
              Save changes
            </button>
          </section>

          <!-- Notifications -->
          <section v-else-if="activeSection === 'notifications'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">Notifications</h2>
            <label class="flex items-center justify-between rounded-control border border-border p-2.5 text-sm">
              Email on execution
              <input v-model="form.notifications.email_on_execution" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
            </label>
            <label class="flex items-center justify-between rounded-control border border-border p-2.5 text-sm">
              Email on rejection
              <input v-model="form.notifications.email_on_rejection" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
            </label>
            <label class="flex items-center justify-between rounded-control border border-border p-2.5 text-sm">
              Email on kill switch
              <input v-model="form.notifications.email_on_kill_switch" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
            </label>
            <label class="flex items-center justify-between rounded-control border border-border p-2.5 text-sm">
              In-app notifications
              <input v-model="form.notifications.in_app" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
            </label>
            <button type="button" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="settings.isSaving" @click="saveSection('notifications')">
              Save changes
            </button>
          </section>

          <!-- Security -->
          <section v-else-if="activeSection === 'security'" class="space-y-3">
            <h2 class="text-sm font-semibold text-ink-950">Security</h2>
            <label class="flex items-center justify-between rounded-control border border-border p-2.5 text-sm">
              Two-factor authentication
              <input v-model="form.security.two_factor_enabled" type="checkbox" class="h-4 w-4 rounded border-border text-brand-600" />
            </label>
            <p class="text-sm text-slate-600">Active sessions: <span class="font-semibold text-ink-950">{{ form.security.active_sessions }}</span></p>
            <button type="button" class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60" :disabled="settings.isSaving" @click="saveSection('security')">
              Save changes
            </button>
          </section>

          <!-- System health -->
          <section v-else-if="activeSection === 'health'" class="space-y-3">
            <div class="flex items-center gap-2">
              <h2 class="text-sm font-semibold text-ink-950">System Health</h2>
              <Badge :tone="overallHealthy ? 'success' : 'warning'">{{ settings.systemHealth?.overall ?? '--' }}</Badge>
            </div>
            <div class="scroll-x">
              <table class="w-full min-w-[640px] text-sm">
                <thead>
                  <tr class="border-b border-border text-left text-xs text-slate-600">
                    <th class="py-2 font-medium">Component</th>
                    <th class="py-2 font-medium">Status</th>
                    <th class="py-2 font-medium">Detail</th>
                    <th class="py-2 font-medium">Latency</th>
                    <th class="py-2 font-medium">Checked</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in settings.systemHealth?.components ?? []" :key="c.key" class="border-b border-border last:border-0">
                    <td class="py-2 font-medium text-ink-950">{{ c.label }}</td>
                    <td class="py-2">
                      <Badge :tone="healthTone(c.status)">
                        <component :is="c.status === 'operational' ? CircleCheck : CircleX" :size="12" />
                        {{ c.status?.replaceAll('_', ' ') ?? '--' }}
                      </Badge>
                    </td>
                    <td class="py-2 text-xs text-slate-600">{{ c.detail }}</td>
                    <td class="tabular-nums py-2 text-xs">{{ c.latency_ms ? `${formatNumber(c.latency_ms)}ms` : '--' }}</td>
                    <td class="py-2 text-xs text-slate-600">{{ formatDateTime(c.checked_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>
      </div>
    </div>

    <ConfirmDialog
      :open="modeDialogOpen"
      title="Change autonomy mode?"
      :description="`Switching to '${pendingMode?.replaceAll('_', ' ')}' changes how much AURA can do without a human click and is recorded in the audit log.`"
      confirm-label="Confirm change"
      @confirm="confirmModeChange"
      @cancel="cancelModeChange"
    />
  </div>
</template>
