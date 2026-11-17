import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const backend = fileURLToPath(new URL('../../backend/', import.meta.url))

function artisan(...args: string[]): void {
  execFileSync('php', ['artisan', ...args, '--env=e2e'], { cwd: backend, stdio: 'inherit' })
}

/** Fresh schema, context key, reference data and the two demo organizations. */
export default function globalSetup(): void {
  artisan('migrate:fresh', '--database=pgsql_owner', '--force')
  artisan('db:context-key', 'install')
  artisan('db:seed', '--database=pgsql_owner', '--force')
  artisan('db:seed', '--database=pgsql_owner', '--class=DemoSeeder', '--force')
}
