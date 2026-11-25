import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/** Password of every DemoSeeder account during E2E runs (test database only). */
export const E2E_PASSWORD = 'E2e-Rahasia-2026'

const backend = fileURLToPath(new URL('../../backend/', import.meta.url))

/**
 * backend/.env.e2e: the developer's backend/.env (database roles and passwords) pointed at the
 * test database and the E2E ports. Written on every run, never committed (backend/.gitignore).
 */
export function writeEnv(): void {
  const source = resolve(backend, '.env')
  if (!existsSync(source)) throw new Error('backend/.env tidak ditemukan: siapkan backend lebih dulu (README).')
  const overrides: Record<string, string> = {
    // `artisan serve` passes APP_ENV on to its PHP process, which then loads .env.e2e again
    // (with 'local' it would fall back to .env and the development database).
    APP_ENV: 'e2e',
    APP_URL: 'http://localhost:8001',
    DB_DATABASE: 'asset_tracker_v1_test',
    SANCTUM_STATEFUL_DOMAINS: 'localhost:5174,127.0.0.1:5174',
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    DEMO_PASSWORD: E2E_PASSWORD,
    MAIL_MAILER: 'log',
    QUEUE_CONNECTION: 'sync',
    // The general categories DemoSeeder uses, plus the cylinders of the PT SIG / PT CDO edition.
    EDITION_CATEGORIES: 'AST_TABUNG,AST_KENDARAAN,AST_IT,AST_FURNITURE',
  }
  const original = readFileSync(source, 'utf8')
  // A successful E2E login then proves the servers use the E2E database, not the development one.
  if (new RegExp(`^DEMO_PASSWORD=${E2E_PASSWORD}\\s*$`, 'm').test(original)) {
    throw new Error('DEMO_PASSWORD di backend/.env sama dengan password E2E; ubah salah satunya.')
  }
  const lines = original.split(/\r?\n/).filter((l) => !Object.keys(overrides).some((k) => l.startsWith(`${k}=`)))
  writeFileSync(resolve(backend, '.env.e2e'), [...lines, '# --- E2E overrides (frontend/e2e/global-setup.ts) ---', ...Object.entries(overrides).map(([k, v]) => `${k}=${v}`), ''].join('\n'))
}

