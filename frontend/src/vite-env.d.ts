/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_MODE: 'fixture' | 'live'
  readonly VITE_API_BASE_URL: string
  readonly VITE_FIXTURE_DELAY_MS?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
