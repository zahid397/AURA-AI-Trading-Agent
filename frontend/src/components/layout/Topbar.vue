<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bell, ChevronDown, Clock3, LogOut, Menu, ShieldCheck, User, WifiOff } from 'lucide-vue-next'
import { API_MODE } from '@/services/apiMode'
import { useAuthStore } from '@/stores/auth'
import { useRiskStore } from '@/stores/risk'
import { useSettingsStore } from '@/stores/settings'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { formatTime } from '@/utils/format'

const ui = useUiNotificationsStore()
const auth = useAuthStore()
const settings = useSettingsStore()
const risk = useRiskStore()
const route = useRoute()
const router = useRouter()

const userMenuOpen = ref(false)
const notificationsOpen = ref(false)

const pageTitle = computed(() => (typeof route.meta.title === 'string' ? route.meta.title : 'AURA'))

onMounted(() => {
  if (!settings.marketClock) void settings.refreshMarketClock()
  const interval = setInterval(() => void settings.refreshMarketClock(), 60000)
  onUnmounted(() => clearInterval(interval))
})

const marketClockLabel = computed(() => {
  const clock = settings.marketClock
  if (!clock) return '--'
  return `${clock.is_open ? 'Market open' : 'Market closed'} · ${formatTime(clock.server_time)} ET`
})

const unacknowledgedAlerts = computed(() => risk.alerts.filter((a) => !a.acknowledged_at))

async function handleLogout(): Promise<void> {
  userMenuOpen.value = false
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-border bg-surface/95 backdrop-blur">
    <div class="flex h-14 items-center gap-3 px-3 sm:px-4">
      <button type="button" class="rounded p-1.5 text-slate-600 hover:bg-canvas md:hidden" aria-label="Open navigation" @click="ui.toggleMobileDrawer(true)">
        <Menu :size="20" />
      </button>

      <div class="min-w-0 flex-1">
        <h1 class="truncate text-base font-semibold text-ink-950">{{ pageTitle }}</h1>
      </div>

      <span
        class="hidden shrink-0 items-center gap-1.5 rounded-full border border-success/30 bg-success-50 px-2.5 py-1 text-xs font-semibold text-success sm:inline-flex"
        title="This environment only ever trades against Alpaca's paper account. No real money is at risk."
      >
        <ShieldCheck :size="13" />
        PAPER TRADING<template v-if="API_MODE === 'fixture'"> · DEMO DATA</template>
      </span>

      <span
        v-if="ui.isDegraded"
        class="hidden shrink-0 items-center gap-1.5 rounded-full border border-warning/30 bg-warning-50 px-2.5 py-1 text-xs font-semibold text-warning lg:inline-flex"
      >
        <WifiOff :size="13" />
        API unreachable — demo data
      </span>

      <span class="hidden shrink-0 items-center gap-1.5 text-xs font-medium text-slate-600 md:inline-flex">
        <Clock3 :size="14" />
        {{ marketClockLabel }}
      </span>

      <div class="relative shrink-0">
        <button
          type="button"
          class="relative rounded p-1.5 text-slate-600 hover:bg-canvas"
          aria-label="Notifications"
          @click="notificationsOpen = !notificationsOpen"
        >
          <Bell :size="18" />
          <span
            v-if="unacknowledgedAlerts.length > 0"
            class="absolute right-1 top-1 flex h-2 w-2 rounded-full bg-danger"
            aria-hidden="true"
          />
        </button>
        <div
          v-if="notificationsOpen"
          class="absolute right-0 z-40 mt-2 w-80 rounded-card border border-border bg-surface p-2 shadow-lg animate-fade-in"
          role="menu"
        >
          <p class="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">Risk alerts</p>
          <p v-if="unacknowledgedAlerts.length === 0" class="px-2 py-3 text-sm text-slate-600">No unacknowledged alerts.</p>
          <router-link
            v-for="alert in unacknowledgedAlerts.slice(0, 5)"
            :key="alert.id"
            :to="{ name: 'risk-management' }"
            class="block rounded-control px-2 py-2 text-sm hover:bg-canvas"
            @click="notificationsOpen = false"
          >
            <p class="font-medium text-ink-950">{{ alert.title }}</p>
            <p class="mt-0.5 line-clamp-2 text-xs text-slate-600">{{ alert.message }}</p>
          </router-link>
        </div>
      </div>

      <div class="relative shrink-0">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-control px-1.5 py-1 hover:bg-canvas"
          aria-label="User menu"
          @click="userMenuOpen = !userMenuOpen"
        >
          <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-brand-700">
            <User :size="14" />
          </span>
          <span class="hidden text-sm font-medium text-ink-950 sm:inline">{{ auth.user?.name ?? 'Demo Trader' }}</span>
          <ChevronDown :size="14" class="hidden text-slate-600 sm:inline" />
        </button>
        <div
          v-if="userMenuOpen"
          class="absolute right-0 z-40 mt-2 w-48 rounded-card border border-border bg-surface p-1 shadow-lg animate-fade-in"
          role="menu"
        >
          <p class="truncate px-2 py-1.5 text-xs text-slate-600">{{ auth.user?.email }}</p>
          <router-link :to="{ name: 'settings' }" class="block rounded-control px-2 py-1.5 text-sm text-ink-950 hover:bg-canvas" @click="userMenuOpen = false">
            Settings
          </router-link>
          <button type="button" class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm text-danger hover:bg-danger-50" @click="handleLogout">
            <LogOut :size="14" />
            Sign out
          </button>
        </div>
      </div>
    </div>
  </header>
</template>
