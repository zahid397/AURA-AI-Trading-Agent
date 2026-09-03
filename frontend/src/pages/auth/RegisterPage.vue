<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { AlertCircle, Eye, EyeOff, ShieldCheck } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const showPassword = ref(false)

const passwordsMismatch = computed(
  () => passwordConfirmation.value.length > 0 && password.value !== passwordConfirmation.value,
)

async function onSubmit(): Promise<void> {
  if (passwordsMismatch.value) return
  const ok = await auth.register(name.value, email.value, password.value, passwordConfirmation.value)
  if (ok) {
    await router.push('/dashboard')
  }
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
            <label for="name" class="mb-1 block text-sm font-medium text-ink-950">Name</label>
            <input
              id="name"
              v-model="name"
              type="text"
              autocomplete="name"
              required
              class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
              placeholder="Jane Trader"
            />
          </div>
          <div>
            <label for="register-email" class="mb-1 block text-sm font-medium text-ink-950">Email</label>
            <input
              id="register-email"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
              placeholder="you@example.com"
            />
          </div>
          <div>
            <label for="register-password" class="mb-1 block text-sm font-medium text-ink-950">Password</label>
            <div class="relative">
              <input
                id="register-password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="new-password"
                required
                class="w-full rounded-control border border-border bg-surface px-3 py-2 pr-9 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
                placeholder="At least 8 characters"
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
          <div>
            <label for="password-confirmation" class="mb-1 block text-sm font-medium text-ink-950">Confirm password</label>
            <input
              id="password-confirmation"
              v-model="passwordConfirmation"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              required
              class="w-full rounded-control border px-3 py-2 text-sm text-ink-950 outline-none focus-visible:border-brand-600"
              :class="passwordsMismatch ? 'border-danger' : 'border-border'"
              placeholder="••••••••"
            />
            <p v-if="passwordsMismatch" class="mt-1 text-xs text-danger">Passwords do not match.</p>
          </div>

          <div v-if="auth.error" class="flex items-start gap-1.5 rounded-control border border-danger/20 bg-danger-50 px-2.5 py-2 text-xs font-medium text-danger">
            <AlertCircle :size="14" class="mt-0.5 shrink-0" />
            {{ auth.error }}
          </div>

          <button
            type="submit"
            class="w-full rounded-control bg-brand-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700 disabled:opacity-60"
            :disabled="auth.isLoading || passwordsMismatch"
          >
            {{ auth.isLoading ? 'Creating account…' : 'Create account' }}
          </button>
        </form>

        <p class="mt-4 text-center text-xs text-slate-600">
          A new account starts in <span class="font-medium text-ink-950">approval required</span> mode — nothing trades
          automatically until you opt in under Settings.
        </p>
      </div>

      <p class="mt-4 text-center text-sm text-slate-600">
        Already have an account?
        <RouterLink to="/login" class="font-medium text-brand-700 hover:underline">Sign in</RouterLink>
      </p>
    </div>
  </div>
</template>
