import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// https://vite.dev/config/
const viteConfig = defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})

// Vitest's own `defineConfig`/`UserConfig` types bundle a nested, older copy of Vite
// (a version mismatch with this project's top-level `vite`), so merging the `test` field
// through Vitest's helpers produces a `Plugin` type conflict. Vite only ever reads this
// module's default export at runtime -- `defineConfig` itself is purely an editor-typing
// aid -- so the `test` block is appended after typing the Vite-only config normally,
// avoiding the cross-version type clash entirely while Vitest still picks up `test` fine.
export default {
  ...viteConfig,
  test: {
    environment: 'jsdom',
    globals: false,
    css: true,
    exclude: ['**/node_modules/**', '**/e2e/**', '**/dist/**'],
    setupFiles: ['./src/test-setup.ts'],
  },
}
