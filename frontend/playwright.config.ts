import { defineConfig, devices } from '@playwright/test'
import { writeEnv } from './e2e/env.ts'

/**
 * End-to-end tests (F-QA, T-13). They run against their own servers on ports 8001 (Laravel)
 * and 5174 (Vite), so the development servers on 8000/5173 keep running untouched, and against
 * the test database (asset_tracker_v1_test), which e2e/global-setup.ts resets and seeds with
 * DemoSeeder before every run. Do not run them while `php artisan test` is running: both reset
 * that database. See e2e/README.md.
 */
// Before the servers start: `php artisan serve --env=e2e` reads backend/.env.e2e.
writeEnv()

const BACKEND_PORT = 8001
const FRONTEND_PORT = 5174

export default defineConfig({
  testDir: './e2e',
  // One database for every test: run them one after another.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: `http://localhost:${FRONTEND_PORT}`,
    locale: 'id-ID',
    timezoneId: 'Asia/Jakarta',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: `php artisan serve --env=e2e --port=${BACKEND_PORT}`,
      cwd: '../backend',
      env: { APP_ENV: 'e2e' },
      url: `http://localhost:${BACKEND_PORT}/up`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: `npx vite --port ${FRONTEND_PORT} --strictPort`,
      url: `http://localhost:${FRONTEND_PORT}`,
      env: { VITE_BACKEND_URL: `http://localhost:${BACKEND_PORT}` },
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
})
