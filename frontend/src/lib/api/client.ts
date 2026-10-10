/**
 * Minimal API client for the same-origin Laravel backend.
 *
 * Authentication is a Sanctum session cookie (HttpOnly) — no token is ever stored in
 * JavaScript. Mutating requests send the X-XSRF-TOKEN header read from the XSRF-TOKEN
 * cookie, which Laravel sets on /sanctum/csrf-cookie.
 */

export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly fields: FieldErrors
  readonly details: Record<string, unknown>
  readonly requestId: string | null

  constructor(status: number, code: string, message: string, details: Record<string, unknown> = {}, requestId: string | null = null) {
    super(message)
    this.status = status
    this.code = code
    this.details = details
    this.fields = (details.fields as FieldErrors | undefined) ?? {}
    this.requestId = requestId
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

let csrfReady: Promise<void> | null = null

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined)
  try {
    await csrfReady
  } finally {
    csrfReady = null
  }
}

/** Handlers notified on 401 (session ended) so the app can return to the login page. */
const unauthorizedListeners = new Set<() => void>()
export function onUnauthorized(listener: () => void): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

export async function apiRequest<T>(method: Method, path: string, body?: unknown, retried = false): Promise<T> {
  if (method !== 'GET') await ensureCsrfCookie()

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  let response: Response
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa koneksi Anda.')
  }

  if (response.status === 204) return undefined as T

  const payload = await response.json().catch(() => null)
  if (response.ok) return payload as T

  const error = payload?.error
  // Expired CSRF token (e.g. after session regeneration): refresh it once and retry.
  if (response.status === 419 && !retried) {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
    return apiRequest<T>(method, path, body, true)
  }
  if (response.status === 401) unauthorizedListeners.forEach((l) => l())

  throw new ApiError(
    response.status,
    error?.code ?? 'HTTP_ERROR',
    error?.message ?? 'Permintaan tidak dapat diproses.',
    error?.details ?? {},
    error?.request_id ?? response.headers.get('X-Request-Id'),
  )
}

export const api = {
  get: <T>(path: string) => apiRequest<T>('GET', path),
  post: <T>(path: string, body?: unknown) => apiRequest<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => apiRequest<T>('PUT', path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>('PATCH', path, body ?? {}),
  delete: <T>(path: string) => apiRequest<T>('DELETE', path),
}

/** Build a query string, skipping empty values. */
export function qs(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value))
  }
  const s = search.toString()
  return s ? `?${s}` : ''
}
