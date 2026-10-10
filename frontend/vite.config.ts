/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const backend = process.env.VITE_BACKEND_URL ?? 'http://localhost:8000'

// The SPA and the API share one origin: in development Vite proxies /api and /sanctum
// to Laravel; in production Nginx serves the build and forwards /api to PHP-FPM.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    // Sanctum only treats SANCTUM_STATEFUL_DOMAINS as first-party; silently falling back to
    // 5174 would drop the session cookie and make every authenticated request 401.
    strictPort: true,
    proxy: {
      '/api': { target: backend, changeOrigin: false },
      '/sanctum': { target: backend, changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
