import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import * as authService from '@/services/auth'
import { ApiError } from '@/services/http'
import type { User, UserSettings } from '@/types/api'

const STORAGE_KEY = 'aura.auth.session'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const settings = ref<UserSettings | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  /** Have we already tried to restore a session this app load? Guards the router bootstrap. */
  const hasBootstrapped = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  function persistSessionHint(): void {
    try {
      sessionStorage.setItem(STORAGE_KEY, '1')
    } catch {
      // sessionStorage can throw in locked-down environments (private browsing, etc.) -- non-fatal.
    }
  }

  function clearSessionHint(): void {
    try {
      sessionStorage.removeItem(STORAGE_KEY)
    } catch {
      // ignore
    }
  }

  function hadSessionHint(): boolean {
    try {
      return sessionStorage.getItem(STORAGE_KEY) === '1'
    } catch {
      return false
    }
  }

  async function login(email: string, password: string): Promise<boolean> {
    isLoading.value = true
    error.value = null
    try {
      const me = await authService.login(email, password)
      user.value = me.user
      settings.value = me.settings
      persistSessionHint()
      return true
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Unable to sign in. Please try again.'
      return false
    } finally {
      isLoading.value = false
    }
  }

  async function register(name: string, email: string, password: string, passwordConfirmation: string): Promise<boolean> {
    isLoading.value = true
    error.value = null
    try {
      const me = await authService.register(name, email, password, passwordConfirmation)
      user.value = me.user
      settings.value = me.settings
      persistSessionHint()
      return true
    } catch (err) {
      // Surface the first field-level validation message (e.g. "The email
      // has already been taken.") when the API supplies one -- more useful
      // than the generic "The given data was invalid." envelope message.
      if (err instanceof ApiError) {
        const firstFieldError = err.fields ? Object.values(err.fields)[0]?.[0] : undefined
        error.value = firstFieldError ?? err.message
      } else {
        error.value = 'Unable to create an account. Please try again.'
      }
      return false
    } finally {
      isLoading.value = false
    }
  }

  async function logout(): Promise<void> {
    isLoading.value = true
    try {
      await authService.logout()
    } catch {
      // Logging out client-side regardless of API result -- never trap a user in a broken session.
    } finally {
      user.value = null
      settings.value = null
      clearSessionHint()
      isLoading.value = false
    }
  }

  /** Called once at router bootstrap to restore a session (cookie or fixture hint) without forcing a fresh login every reload. */
  async function bootstrap(): Promise<void> {
    if (hasBootstrapped.value) return
    hasBootstrapped.value = true
    if (!hadSessionHint()) return
    try {
      const me = await authService.fetchMe()
      user.value = me.user
      settings.value = me.settings
    } catch {
      clearSessionHint()
      user.value = null
    }
  }

  return {
    user,
    settings,
    isLoading,
    error,
    isAuthenticated,
    hasBootstrapped,
    login,
    register,
    logout,
    bootstrap,
  }
})
