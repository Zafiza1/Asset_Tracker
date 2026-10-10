export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

export interface Resource<T> {
  data: T
}

export interface Organization {
  id: string
  code: string
  name: string
  legal_name: string | null
  status: 'active' | 'suspended' | 'archived'
  timezone: string
  locale: string
  currency: string
  email: string | null
  phone: string | null
  tax_id: string | null
  address: string | null
  created_at: string
  updated_at: string
}

export interface OrganizationSettings {
  asset_number_format: string
  transaction_number_format: string
  allow_self_approval_default: boolean
  max_upload_mb: number
  updated_at: string
}

export type UserStatus = 'invited' | 'active' | 'suspended' | 'deactivated'
export type ScopeType = 'organization' | 'branch' | 'department' | 'location'

export interface DataScopeEntry {
  scope_type: ScopeType
  ref_id: string | null
}

export interface User {
  id: string
  name: string
  email: string
  status: UserStatus
  employee_number: string | null
  job_title: string | null
  phone: string | null
  home_branch_id: string | null
  home_department_id: string | null
  must_change_password: boolean
  last_login_at: string | null
  roles?: { id: string; code: string; name: string }[]
  data_scopes?: DataScopeEntry[]
  created_at: string
  updated_at: string
}

export interface Role {
  id: string
  code: string
  name: string
  description: string | null
  template_code: string | null
  is_locked: boolean
  users_count?: number
  permissions?: string[]
  created_at: string
  updated_at: string
}

export interface Permission {
  code: string
  group: string
  description: string
}

export interface AuditLog {
  id: string
  organization_id: string | null
  actor_type: 'user' | 'system' | 'anonymous'
  actor: { id: string; name: string; email: string } | null
  action: string
  entity_type: string | null
  entity_id: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  metadata: Record<string, unknown> | null
  ip: string | null
  request_id: string | null
  created_at: string
}

export interface Profile {
  user: {
    id: string
    name: string
    email: string
    user_type: 'tenant' | 'platform'
    job_title: string | null
    must_change_password: boolean
    last_login_at: string | null
  }
  organization: Organization | null
  permissions: string[]
  data_scope: { organization_wide: boolean; branch_ids: string[]; department_ids: string[]; location_ids: string[] } | null
}
