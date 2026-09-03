<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ChevronsLeft, ChevronsRight, Power, ShieldCheck, X, Zap } from 'lucide-vue-next'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { useRiskStore } from '@/stores/risk'
import { useSettingsStore } from '@/stores/settings'
import { useUiNotificationsStore } from '@/stores/uiNotifications'
import { NAV_ITEMS } from './navItems'

const ui = useUiNotificationsStore()
const risk = useRiskStore()
const settings = useSettingsStore()

const killSwitchDialogOpen = ref(false)
const isTogglingKillSwitch = ref(false)

const killSwitchEngaged = computed(() => risk.overview?.kill_switch_engaged ?? false)
const healthOverall = computed(() => settings.systemHealth?.overall ?? 'operational')

onMounted(() => {
  if (!risk.overview) void risk.loadAll()
  if (!settings.systemHealth) void settings.loadAll()
})

function openKillSwitchConfirm(): void {
  killSwitchDialogOpen.value = true
}

async function confirmKillSwitch(): Promise<void> {
  isTogglingKillSwitch.value = true
  await risk.toggleKillSwitch(!killSwitchEngaged.value)
  isTogglingKillSwitch.value = false
  killSwitchDialogOpen.value = false
}

const healthTone = computed(() => {
  if (healthOverall.value === 'operational') return 'success'
  if (healthOverall.value === 'degraded') return 'warning'
  return 'danger'
})
</script>

<template>
  <!-- Mobile drawer backdrop -->
  <div
    v-if="ui.mobileDrawerOpen"
    class="fixed inset-0 z-40 bg-ink-950/40 md:hidden"
    @click="ui.toggleMobileDrawer(false)"
  />

  <aside
    class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-border bg-surface transition-transform duration-200 md:static md:z-auto md:w-[72px] md:translate-x-0 lg:w-60"
    :class="[ui.mobileDrawerOpen ? 'translate-x-0' : '', ui.sidebarCollapsed ? 'lg:!w-[72px]' : '']"
    aria-label="Primary navigation"
  >
    <div class="flex h-14 items-center justify-between gap-2 border-b border-border px-3">
      <router-link to="/dashboard" class="flex items-center gap-2 overflow-hidden" @click="ui.toggleMobileDrawer(false)">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">A</span>
        <span v-show="!ui.sidebarCollapsed" class="truncate text-base font-semibold text-ink-950 md:hidden lg:inline-block">AURA</span>
      </router-link>
      <button type="button" class="rounded p-1 text-slate-600 hover:bg-canvas md:hidden" aria-label="Close navigation" @click="ui.toggleMobileDrawer(false)">
        <X :size="18" />
      </button>
      <button
        type="button"
        class="hidden shrink-0 rounded p-1 text-slate-600 hover:bg-canvas lg:block"
        :aria-label="ui.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="ui.toggleSidebar"
      >
        <ChevronsRight v-if="ui.sidebarCollapsed" :size="16" />
        <ChevronsLeft v-else :size="16" />
      </button>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
      <router-link
        v-for="item in NAV_ITEMS"
        :key="item.routeName"
        :to="{ name: item.routeName }"
        class="flex items-center gap-3 rounded-control px-2.5 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-canvas hover:text-ink-950"
        active-class="!bg-brand-50 !text-brand-700"
        :title="item.label"
        @click="ui.toggleMobileDrawer(false)"
      >
        <component :is="item.icon" :size="18" class="shrink-0" aria-hidden="true" />
        <span v-show="!ui.sidebarCollapsed" class="truncate md:hidden lg:inline-block">{{ item.label }}</span>
      </router-link>
    </nav>

    <div class="space-y-2 border-t border-border p-3">
      <div v-show="!ui.sidebarCollapsed" class="rounded-control border border-border bg-canvas p-2.5 md:hidden lg:block">
        <div class="flex items-center gap-1.5 text-xs font-medium">
          <ShieldCheck :size="14" :class="healthTone === 'success' ? 'text-success' : healthTone === 'warning' ? 'text-warning' : 'text-danger'" />
          <span class="text-ink-950">System status</span>
        </div>
        <p class="mt-0.5 text-xs capitalize text-slate-600">{{ healthOverall }}</p>
      </div>

      <button
        type="button"
        class="flex w-full items-center justify-center gap-2 rounded-control px-2.5 py-2 text-sm font-semibold transition-colors"
        :class="killSwitchEngaged ? 'bg-danger text-white hover:bg-danger/90' : 'border border-danger/30 bg-danger-50 text-danger hover:bg-danger/10'"
        @click="openKillSwitchConfirm"
      >
        <Power :size="16" />
        <span v-show="!ui.sidebarCollapsed" class="md:hidden lg:inline-block">{{ killSwitchEngaged ? 'Kill Switch Engaged' : 'Kill Switch' }}</span>
      </button>

      <p v-show="!ui.sidebarCollapsed" class="flex items-center gap-1 px-1 text-[11px] text-slate-600 md:hidden lg:flex">
        <Zap :size="11" />
        Powered by Alpaca — Paper Trading
      </p>
    </div>
  </aside>

  <ConfirmDialog
    :open="killSwitchDialogOpen"
    :title="killSwitchEngaged ? 'Disengage kill switch?' : 'Engage kill switch?'"
    :description="
      killSwitchEngaged
        ? 'Agents will resume normal operation and may propose or execute new paper trades again.'
        : 'This immediately halts all agent activity and blocks new trade execution across the account. This is a paper-trading safety control.'
    "
    :confirm-label="killSwitchEngaged ? 'Disengage' : 'Engage kill switch'"
    danger
    :loading="isTogglingKillSwitch"
    @confirm="confirmKillSwitch"
    @cancel="killSwitchDialogOpen = false"
  />
</template>
