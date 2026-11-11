/** Error envelope of the backend: { error: { code, message, details } }. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message)
  }

  /** Validation errors per input ({ name: ['…'] }), as details.fields of a 422. */
  get fields(): Record<string, string[]> {
    return (this.details.fields as Record<string, string[]> | undefined) ?? {}
  }
}

/** A write by a user without approval.manage: 202, stored as a pending approval request. */
export interface ApprovalPending {
  data: { id: string; summary: string; action_label: string; status: string }
  meta: { approval_pending: true; message: string }
}

export function isApprovalPending(response: unknown): response is ApprovalPending {
  return (response as ApprovalPending | null)?.meta?.approval_pending === true
}

const TIMEOUT_MS = 15_000

/** "10.10.20.7:8000/" or "http://host/api" -> "http://10.10.20.7:8000" / "http://host". */
export function normalizeServer(input: string): string {
  let url = input.trim().replace(/\/+$/, '').replace(/\/api$/i, '')
  if (url && !/^https?:\/\//i.test(url)) url = `http://${url}`
  return url
}

interface Options {
  method?: 'GET' | 'POST'
  token?: string | null
  body?: unknown
}

/** JSON request to {server}/api{path}; failures throw ApiError (status 0 = no connection). */
export async function request<T>(server: string, path: string, { method = 'GET', token, body }: Options = {}): Promise<T> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS)
  let response: Response
  let json: any = null
  try {
    response = await fetch(`${server}/api${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined && { 'Content-Type': 'application/json' }),
        ...(token && { Authorization: `Bearer ${token}` }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    })
    // Inside the timeout too: a server that sends headers and then stalls must not hang the screen.
    if (response.status !== 204) json = await response.json().catch((e) => (controller.signal.aborted ? Promise.reject(e) : null))
  } catch {
    if (controller.signal.aborted) throw new ApiError(0, 'TIMEOUT', 'Server terlalu lama merespons. Coba lagi.')
    throw new ApiError(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa alamat server dan jaringan.')
  } finally {
    clearTimeout(timer)
  }

  if (response.status === 204) return undefined as T
  if (!response.ok) {
    const error = json?.error
    throw new ApiError(
      response.status,
      error?.code ?? (response.status === 401 ? 'UNAUTHENTICATED' : 'HTTP_ERROR'),
      error?.message ?? (response.status === 401 ? 'Sesi berakhir. Silakan masuk kembali.' : `Terjadi kesalahan (${response.status}).`),
      error?.details ?? {},
    )
  }
  return json as T
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Terjadi kesalahan.'
}
