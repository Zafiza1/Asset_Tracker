export type Membership = { id: number; name: string; organization_id?: number }
export type Session = {
  token: string
  organizationId?: number
  projectId?: number
  user?: { name?: string; email?: string; organizations?: Membership[]; projects?: Membership[] }
}
export type PageMeta = { current_page: number; per_page: number; total: number; last_page: number }
export type ApiList<T> = { data: T[]; meta?: PageMeta }
export type BoundDevice = {
  system_id: string; serial_number?: string; name: string; status: string; type?: string
  last_seen_at?: string | null; bound_at?: string | null
  integration?: { id: number; name: string; type: string; status: string } | null
}
export type Asset = {
  system_id: string; serial_number: string; name: string; description?: string | null
  asset_type?: string | null; status: string; metadata?: Record<string, unknown> | null
  current_location?: { id: number; name: string; type?: string } | null
  devices?: BoundDevice[]; last_seen_at?: string | null; created_at?: string; updated_at?: string
}
export type AssetInput = { serial_number: string; name: string; description?: string; asset_type?: string; status?: string; metadata?: Record<string, unknown> }
export type AssetQuery = { page?: number; per_page?: number; search?: string; status?: string; asset_type?: string; sort?: string }
export type Location = { id: number; name: string; type?: string; address?: string }
export type Movement = { id: number; asset?: { name: string; system_id: string }; from_location?: { name: string } | null; to_location?: { name: string } | null; occurred_at: string; source?: string }
export type ActivityEntry = { kind: 'activity' | 'event'; type: string; actor?: string | null; source?: string | null; occurred_at: string }
export type CustomField = { id: number; key: string; label: string; type: string; required: boolean; active: boolean; options?: string[] | null }
export type Dashboard = {
  totals: { assets: number; active: number; tracked: number; offline: number }
  by_status: Record<string, number>
  by_type: Record<string, number>
  by_location: { location_id: number; name: string; count: number }[]
  integrations: Record<string, number>
  recent_activity: { id: number; action: string; asset_system_id?: string | null; asset_name?: string | null; user?: string | null; occurred_at: string }[]
  offline_after_minutes: number
}

const key = 'asset-tracker-session'
const baseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api'

export const getSession = (): Session | null => { try { const value = localStorage.getItem(key); return value ? JSON.parse(value) : null } catch { return null } }
export const saveSession = (session: Session | null) => { try { if (session) localStorage.setItem(key, JSON.stringify(session)); else localStorage.removeItem(key) } catch { /* storage unavailable: session lives in memory only */ } }

export class ApiError extends Error {
  constructor(message: string, public status: number, public errors: Record<string, string[]> = {}) { super(message) }
}

const toQuery = (params: Record<string, string | number | undefined>) => {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([name, value]) => { if (value !== undefined && value !== '') search.set(name, String(value)) })
  const text = search.toString()
  return text ? `?${text}` : ''
}

export class ApiClient {
  constructor(private session: Session) {}

  /** Returns the full envelope ({success, data, meta?}). */
  private async send<T>(path: string, init?: RequestInit): Promise<{ data: T; meta?: PageMeta }> {
    const headers: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${this.session.token}`, ...(init?.headers as Record<string, string> ?? {}) }
    if (init?.body) headers['Content-Type'] = 'application/json'
    if (this.session.organizationId) headers['X-Organization-Id'] = String(this.session.organizationId)
    if (this.session.projectId) headers['X-Project-Id'] = String(this.session.projectId)
    const response = await fetch(`${baseUrl}${path}`, { ...init, headers })
    const body = await response.json().catch(() => ({}))
    if (!response.ok || body.success === false) throw new ApiError(body.message ?? 'Request failed', response.status, body.errors)
    return body
  }
  private async request<T>(path: string, init?: RequestInit): Promise<T> { return (await this.send<T>(path, init)).data }
  private list<T>(path: string): Promise<ApiList<T>> { return this.send<T[]>(path) }
  private json(method: string, body: unknown): RequestInit { return { method, body: JSON.stringify(body) } }

  login(email: string, password: string) {
    return fetch(`${baseUrl}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email, password, device_name: 'web-dashboard' }) })
      .then(async r => { const b = await r.json(); if (!r.ok || !b.success) throw new Error(b.message ?? 'Login failed'); return b.data })
  }

  dashboard() { return this.request<Dashboard>('/v1/dashboard') }

  assets(query: AssetQuery = {}) { return this.list<Asset>(`/v1/assets${toQuery(query)}`) }
  asset(systemId: string) { return this.request<Asset>(`/v1/assets/${encodeURIComponent(systemId)}`) }
  createAsset(input: AssetInput) { return this.request<Asset>('/v1/assets', this.json('POST', input)) }
  updateAsset(systemId: string, input: Partial<AssetInput>) { return this.request<Asset>(`/v1/assets/${encodeURIComponent(systemId)}`, this.json('PUT', input)) }
  deleteAsset(systemId: string) { return this.request<void>(`/v1/assets/${encodeURIComponent(systemId)}`, { method: 'DELETE' }) }
  assetActivity(systemId: string) { return this.request<ActivityEntry[]>(`/v1/assets/${encodeURIComponent(systemId)}/activity`) }
  movements(systemId: string) { return this.list<Movement>(`/v1/assets/${encodeURIComponent(systemId)}/movements`) }
  moveAsset(systemId: string, toLocationId: number) { return this.request<Movement>(`/v1/assets/${encodeURIComponent(systemId)}/movements`, this.json('POST', { to_location_id: toLocationId })) }

  locations() { return this.list<Location>('/v1/locations?per_page=100') }
  createLocation(input: Omit<Location, 'id'>) { return this.request<Location>('/v1/locations', this.json('POST', input)) }
  deleteLocation(id: number) { return this.request<void>(`/v1/locations/${id}`, { method: 'DELETE' }) }

  customFields() { return this.request<CustomField[]>('/v1/custom-fields') }
  createCustomField(field: Pick<CustomField, 'key' | 'label' | 'type' | 'required'>) { return this.request<CustomField>('/v1/custom-fields', this.json('POST', field)) }
  deleteCustomField(id: number) { return this.request<void>(`/v1/custom-fields/${id}`, { method: 'DELETE' }) }
}
