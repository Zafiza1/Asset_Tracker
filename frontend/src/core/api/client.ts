export type Session = { token: string; organizationId?: number; projectId?: number; user?: { name?: string; email?: string } }
export type ApiList<T> = { data: T[]; meta?: { total: number } }
export type Asset = { system_id: string; serial_number: string; name: string; asset_type?: string; status: string; current_location?: { name: string } | null }
export type Location = { id: number; name: string; type?: string; address?: string }
export type Movement = { id: number; asset?: { name: string; system_id: string }; from_location?: { name: string } | null; to_location?: { name: string } | null; occurred_at: string; source?: string }
const key = 'asset-tracker-session'
export const getSession = (): Session | null => { try { const value = localStorage.getItem(key); return value ? JSON.parse(value) : null } catch { return null } }
export const saveSession = (session: Session | null) => session ? localStorage.setItem(key, JSON.stringify(session)) : localStorage.removeItem(key)

export class ApiClient {
  constructor(private session: Session) {}
  private async request<T>(path: string, init?: RequestInit): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${this.session.token}`, ...(init?.headers as Record<string, string> ?? {}) }
    if (this.session.organizationId) headers['X-Organization-Id'] = String(this.session.organizationId)
    if (this.session.projectId) headers['X-Project-Id'] = String(this.session.projectId)
    const response = await fetch(`${import.meta.env.VITE_API_BASE_URL ?? '/api'}${path}`, { ...init, headers })
    const body = await response.json().catch(() => ({}))
    if (!response.ok || !body.success) throw new Error(body.message ?? 'Request failed')
    return body.data as T
  }
  login(email: string, password: string) { return fetch(`${import.meta.env.VITE_API_BASE_URL ?? '/api'}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email, password, device_name: 'web-dashboard' }) }).then(async r => { const b = await r.json(); if (!r.ok || !b.success) throw new Error(b.message ?? 'Login failed'); return b.data }) }
  assets() { return this.request<ApiList<Asset>>('/v1/assets') }
  locations() { return this.request<ApiList<Location>>('/v1/locations') }
  movements(assetId: string) { return this.request<ApiList<Movement>>(`/v1/assets/${encodeURIComponent(assetId)}/movements`) }
}
