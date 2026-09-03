<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AlertCircle, Eye, EyeOff, ShieldCheck } from 'lucide-vue-next'
import { DEMO_CREDENTIALS } from '@/dev/fixtures/auth'
import { API_MODE } from '@/services/apiMode'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref(API_MODE === 'fixture' ? DEMO_CREDENTIALS.email : '')
const password = ref(API_MODE === 'fixture' ? DEMO_CREDENTIALS.password : '')
const showPassword = ref(false)

async function onSubmit(): Promise<void> {
  const ok = await auth.login(email.value, password.value)
  if (ok) {
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    await router.push(redirect)
  }
}

function fillDemoCredentials(): void {
  email.value = DEMO_CREDENTIALS.email
  password.value = DEMO_CREDENTIALS.password
}
</script>

<template>
  <div class="flex min-h-svh items-center justify-center bg-canvas px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-6 flex flex-col items-center gap-2 text-center">
        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">A</span>
        <h1 class="text-xl font-semibold text-ink-950">AURA AI Trading Agent</h1>
        <p class="text-sm text-slate-600">Autonomous options trading &amp; risk intelligence console</p>
      </div>

      <div class="rounded-card border border-border bg-surface p-6 shadow-sm">
        <div class="mb-4 flex items-center gap-1.5 rounded-control border border-success/20 bg-success-50 px-2.5 py-1.5 text-xs font-medium text-success">
          <ShieldCheck :size="14" />
          Paper trading only — no real funds are ever at risk.
        </div>

        <form class="space-y-4" novalidate @submit.prevent="onSubmit">
          <div>
            <label for="email" class="mb-1 block text-sm font-medium text-ink-950">Email</label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
              placeholder="you@example.com"
            />
          </div>
          <div>
            <label for="password" class="mb-1 block text-sm font-medium text-ink-950">Password</label>
            <div class="relative">
              <input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                required
                class="w-full rounded-control border border-border bg-surface px-3 py-2 pr-9 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
                placeholder="••••••••"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-2 flex items-center text-slate-600"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="showPassword = !showPassword"
              >
                <EyeOff v-if="showPassword" :size="16" />
                <Eye v-else :size="16" />
              </button>
            </div>
          </div>

          <div v-if="auth.error" class="flex items-start gap-1.5 rounded-control border border-danger/20 bg-danger-50 px-2.5 py-2 text-xs font-medium text-danger">
            <AlertCircle :size="14" class="mt-0.5 shrink-0" />
            {{ auth.error }}
          </div>

          <button
            type="submit"
            class="w-full rounded-control bg-brand-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700 disabled:opacity-60"
            :disabled="auth.isLoading"
          >
            {{ auth.isLoading ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <div class="mt-4 rounded-control border border-border bg-canvas p-3 text-xs text-slate-600">
          <p class="font-medium text-ink-950">Demo credentials (hackathon judging)</p>
          <p class="mt-1 tabular-nums">{{ DEMO_CREDENTIALS.email }} / {{ DEMO_CREDENTIALS.password }}</p>
          <button type="button" class="mt-2 font-medium text-brand-700 hover:underline" @click="fillDemoCredentials">
            Fill demo credentials
          </button>
        </div>
      </div>

      <p class="mt-4 text-center text-sm text-slate-600">
        Don't have an account?
        <RouterLink to="/register" class="font-medium text-brand-700 hover:underline">Create one</RouterLink>
      </p>
    </div>
  </div>
</template>
