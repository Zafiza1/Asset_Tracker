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
export type CustomFieldInput = { key: string; label: string; type: string; required: boolean; options?: string[]; default_value?: unknown; visibility?: string; sort_order?: number }
export type Dashboard = {
  totals: { assets: number; active: number; tracked: number; offline: number }
  by_status: Record<string, number>
  by_type: Record<string, number>
  by_location: { location_id: number; name: string; count: number }[]
  integrations: Record<string, number>
  recent_activity: { id: number; action: string; asset_system_id?: string | null; asset_name?: string | null; user?: string | null; occurred_at: string }[]
  offline_after_minutes: number
}

// Control plane
export type Organization = { id: number; name: string; slug: string; description?: string | null; status: string; membership?: string | null; projects_count?: number }
export type Project = { id: number; organization_id: number; name: string; slug: string; description?: string | null; status: string; template?: { id: number; slug: string; name: string; version: string } | null; created_at?: string }
export type Member = { id: number; name: string; email: string; status: string; membership?: string | null; roles: string[]; joined_at?: string | null }
export type ModuleVersion = { version: string; status: string; changelog?: string | null; dependencies: Record<string, string>; config_schema: Record<string, unknown>; released_at?: string | null }
export type Module = { slug: string; name: string; description?: string | null; category?: string | null; is_core: boolean; status: string; latest_version?: string | null; versions?: ModuleVersion[] }
export type ProjectModule = { module: string; name: string; category?: string | null; version: string; latest_version?: string | null; upgrade_available: boolean; status: string; configuration: Record<string, unknown>; config_schema: Record<string, unknown>; installed_at?: string | null; enabled_at?: string | null }
export type TemplateVersion = { id: number; version: string; description?: string | null; status: string; released_at?: string | null; modules?: (Module & { version_constraint?: string; required?: boolean })[] }
export type Template = { id: number; name: string; slug: string; description?: string | null; category?: string | null; version: string; status: string; default_modules?: string[] | null; current_version?: TemplateVersion | null }
export type Me = { user: { id: number; name: string; email: string; roles?: { slug: string; pivot?: { organization_id: number | null; project_id: number | null } }[] }; organization_permissions?: string[]; project_permissions?: string[] }

// Runtime plane
export type Integration = { id: number; name: string; type: string; provider?: string | null; status: string; config?: Record<string, unknown>; last_connected_at?: string | null; last_health_check_at?: string | null; created_at: string }
export type IntegrationResult = { success: boolean; message?: string; status?: string; details?: Record<string, unknown> }
export type DeviceType = { id: number; name: string; slug: string; description?: string | null; capabilities?: string[] | null }
export type Device = {
  id: number; system_id: string; serial_number?: string | null; name: string; status: string; last_seen_at?: string | null
  device_type_id: number; integration_id?: number | null
  device_type?: { id: number; name: string; slug: string }; integration?: { id: number; name: string; type: string } | null
  current_binding?: { asset_id: number; asset_system_id?: string | null; asset_name?: string | null; bound_at: string } | null
}
export type DeviceInput = { name: string; device_type_id: number; integration_id?: number; serial_number?: string }
export type Webhook = { id: number; name: string; endpoint: string; secret?: string; has_secret: boolean; events: string[]; active: boolean; retry_policy?: { max_attempts?: number; retry_delay?: number } | null; created_at: string }
export type WebhookInput = { name: string; endpoint: string; events: string[]; active?: boolean }
export type WebhookDelivery = { id: number; event_type: string; status: string; attempt: number; max_attempts: number; response_status?: number | null; error_message?: string | null; delivered_at?: string | null; created_at: string }
export type WebhookStats = { total: number; delivered: number; failed: number; pending: number; retrying: number; success_rate: number }
export type ApiKey = { id: number; name: string; prefix: string; scopes: string[]; integration_id?: number | null; last_used_at?: string | null; expires_at?: string | null; revoked_at?: string | null; created_at?: string; key?: string }
export type ActivityLog = { id: number; user?: { name: string; email: string } | null; action: string; resource_type?: string | null; resource_id?: number | null; ip_address?: string | null; metadata?: Record<string, unknown> | null; occurred_at: string }
export type SecurityLog = { id: number; user?: { name: string; email: string } | null; event_type: string; severity: string; ip_address?: string | null; is_suspicious: boolean; occurred_at: string }
export type EventLog = { id: number; event_type: string; source?: string | null; status: string; asset?: { system_id: string; serial_number: string } | null; device?: { serial_number?: string | null; system_id: string } | null; error_message?: string | null; occurred_at: string; processed_at?: string | null }
export type AuditStats = { activity_logs: { total: number; by_action: Record<string, number> }; security_logs: { total: number; suspicious: number; by_severity: Record<string, number> }; event_logs: { total: number; pending: number; processed: number; failed: number; by_event_type: Record<string, number> } }
export type PlatformHealth = { status: string; timestamp: string; checks: Record<string, { status: string; error?: string }> }
export type IntegrationHealth = { summary: Record<string, number>; integrations: { id: number; name: string; type: string; status: string; health: string; last_health_check_at?: string | null }[] }

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

  register(input: { name: string; email: string; password: string; password_confirmation: string }) {
    return this.request<{ user: { name: string; email: string }; token: string }>('/auth/register', this.json('POST', input))
  }
  createOrganization(name: string) { return this.request<Membership>('/v1/organizations', this.json('POST', { name })) }
  createProject(organizationId: number, name: string) { return this.request<Membership>(`/v1/organizations/${organizationId}/projects`, this.json('POST', { name })) }

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

  /** For endpoints that return a bare JSON body (no {success, data} envelope). */
  private async raw<T>(path: string, init?: RequestInit): Promise<T> { return (await this.send<T>(path, init)) as unknown as T }

  me() { return this.request<Me>('/auth/me') }
  switchProject(projectId: number) { return this.request<unknown>('/auth/switch-project', this.json('POST', { project_id: projectId })) }

  // Control plane: organizations, projects, members, templates
  organizations() { return this.list<Organization>('/v1/organizations?per_page=100') }
  updateOrganization(id: number, input: { name?: string; description?: string }) { return this.request<Organization>(`/v1/organizations/${id}`, this.json('PUT', input)) }
  deleteOrganization(id: number) { return this.request<void>(`/v1/organizations/${id}`, { method: 'DELETE' }) }
  projects(organizationId: number) { return this.list<Project>(`/v1/organizations/${organizationId}/projects?per_page=100`) }
  createProjectFromTemplate(organizationId: number, input: { name: string; description?: string; template_id?: number }) { return this.request<Project>(`/v1/organizations/${organizationId}/projects`, this.json('POST', input)) }
  updateProject(id: number, input: { name?: string; description?: string; status?: string }) { return this.request<Project>(`/v1/projects/${id}`, this.json('PUT', input)) }
  deleteProject(id: number) { return this.request<void>(`/v1/projects/${id}`, { method: 'DELETE' }) }
  members(scope: 'organizations' | 'projects', id: number) { return this.list<Member>(`/v1/${scope}/${id}/members?per_page=100`) }
  addMember(scope: 'organizations' | 'projects', id: number, email: string, role: string) { return this.request<Member>(`/v1/${scope}/${id}/members`, this.json('POST', { email, role })) }
  updateMember(scope: 'organizations' | 'projects', id: number, userId: number, role: string | null) { return this.request<Member>(`/v1/${scope}/${id}/members/${userId}`, this.json('PUT', { role })) }
  removeMember(scope: 'organizations' | 'projects', id: number, userId: number) { return this.request<void>(`/v1/${scope}/${id}/members/${userId}`, { method: 'DELETE' }) }
  templates() { return this.list<Template>('/v1/templates?per_page=100') }
  template(id: number) { return this.request<Template>(`/v1/templates/${id}`) }
  templateVersions(id: number) { return this.list<TemplateVersion>(`/v1/templates/${id}/versions`) }
  templateVersion(id: number, versionId: number) { return this.request<TemplateVersion>(`/v1/templates/${id}/versions/${versionId}`) }

  // Modules
  modules() { return this.list<Module>('/v1/modules?per_page=100') }
  projectModules() { return this.list<ProjectModule>('/v1/project-modules') }
  installModule(module: string, version?: string) { return this.request<ProjectModule>('/v1/project-modules', this.json('POST', { module, version })) }
  moduleAction(module: string, action: 'enable' | 'disable' | 'upgrade') { return this.request<ProjectModule>(`/v1/project-modules/${module}/${action}`, this.json('POST', {})) }
  configureModule(module: string, configuration: Record<string, unknown>) { return this.request<ProjectModule>(`/v1/project-modules/${module}/configuration`, this.json('PUT', { configuration })) }
  uninstallModule(module: string) { return this.request<void>(`/v1/project-modules/${module}`, { method: 'DELETE' }) }

  // Integrations & devices
  integrations() { return this.list<Integration>('/v1/integrations?per_page=100') }
  availableIntegrations() { return this.request<string[]>('/v1/integrations/available') }
  createIntegration(input: { name: string; type: string; provider?: string; config: Record<string, unknown> }) { return this.request<Integration>('/v1/integrations', this.json('POST', input)) }
  deleteIntegration(id: number) { return this.request<void>(`/v1/integrations/${id}`, { method: 'DELETE' }) }
  integrationAction(id: number, action: 'connect' | 'disconnect' | 'test') { return this.send<IntegrationResult>(`/v1/integrations/${id}/${action}`, this.json('POST', {})) }
  integrationHealth(id: number) { return this.raw<IntegrationResult>(`/v1/integrations/${id}/health`) }
  validateIntegrationConfig(type: string, config: Record<string, unknown>) { return this.raw<{ valid: boolean; errors: Record<string, string> }>('/v1/integrations/validate-config', this.json('POST', { type, config })) }
  devices(query: { integration_id?: number; status?: string; search?: string } = {}) { return this.list<Device>(`/v1/devices${toQuery({ per_page: 100, ...query })}`) }
  deviceTypes() { return this.list<DeviceType>('/v1/device-types?per_page=100') }
  createDevice(input: DeviceInput) { return this.request<Device>('/v1/devices', this.json('POST', input)) }
  deleteDevice(systemId: string) { return this.request<void>(`/v1/devices/${encodeURIComponent(systemId)}`, { method: 'DELETE' }) }
  bindDevice(systemId: string, assetSystemId: string, replace = false) { return this.request<unknown>(`/v1/devices/${encodeURIComponent(systemId)}/bind`, this.json('POST', { asset_system_id: assetSystemId, replace })) }
  unbindDevice(systemId: string) { return this.request<unknown>(`/v1/devices/${encodeURIComponent(systemId)}/unbind`, this.json('POST', {})) }

  // Webhooks & API keys
  webhooks() { return this.list<Webhook>('/v1/webhooks?per_page=100') }
  createWebhook(input: WebhookInput) { return this.request<Webhook>('/v1/webhooks', this.json('POST', input)) }
  deleteWebhook(id: number) { return this.request<void>(`/v1/webhooks/${id}`, { method: 'DELETE' }) }
  toggleWebhook(id: number) { return this.request<Webhook>(`/v1/webhooks/${id}/toggle-active`, this.json('POST', {})) }
  testWebhook(id: number) { return this.send<{ success: boolean; status: number; body?: string }>(`/v1/webhooks/${id}/test`, this.json('POST', {})) }
  regenerateWebhookSecret(id: number) { return this.request<{ secret: string }>(`/v1/webhooks/${id}/regenerate-secret`, this.json('POST', {})) }
  webhookDeliveries(id: number) { return this.list<WebhookDelivery>(`/v1/webhooks/${id}/deliveries?per_page=20`) }
  webhookStats(id: number) { return this.request<WebhookStats>(`/v1/webhooks/${id}/stats`) }
  apiKeys() { return this.request<ApiKey[]>('/v1/api-keys') }
  createApiKey(input: { name: string; scopes: string[]; integration_id?: number }) { return this.request<ApiKey>('/v1/api-keys', this.json('POST', input)) }
  revokeApiKey(id: number) { return this.request<void>(`/v1/api-keys/${id}`, { method: 'DELETE' }) }

  // Audit & health
  activityLogs(page = 1) { return this.list<ActivityLog>(`/v1/audit/activity-logs?page=${page}&per_page=25`) }
  securityLogs(page = 1) { return this.list<SecurityLog>(`/v1/audit/security-logs?page=${page}&per_page=25`) }
  eventLogs(page = 1) { return this.list<EventLog>(`/v1/audit/event-logs?page=${page}&per_page=25`) }
  auditStats() { return this.raw<AuditStats>('/v1/audit/stats') }
  platformHealth() { return fetch(`${baseUrl}/health`, { headers: { Accept: 'application/json' } }).then(r => r.json() as Promise<PlatformHealth>) }
  integrationsHealth() { return this.request<IntegrationHealth>('/v1/health/integrations') }

  customFields() { return this.request<CustomField[]>('/v1/custom-fields') }
  createCustomField(field: CustomFieldInput) { return this.request<CustomField>('/v1/custom-fields', this.json('POST', field)) }
  deleteCustomField(id: number) { return this.request<void>(`/v1/custom-fields/${id}`, { method: 'DELETE' }) }
}
