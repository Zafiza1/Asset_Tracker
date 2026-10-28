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

export interface License {
  active: boolean
  over_limit: boolean
  seats: {
    capacity: number
    active_members: number
    pending_invitations: number
    available: number
  }
  licenses: LicenseEntry[]
}

export interface LicenseEntry {
  id: string
  kind: 'base' | 'seats'
  seats: number
  source: 'purchase' | 'trial' | 'migration'
  reference: string | null
  notes: string | null
  starts_at: string
  expires_at: string
  revoked_at: string | null
  revoke_reason: string | null
  granted_by: string | null
  status: 'active' | 'scheduled' | 'expired' | 'revoked'
}

export type LicenseCodeStatus = 'available' | 'assigned' | 'revoked'

export interface LicenseCode {
  id: string
  code: string
  status: LicenseCodeStatus
  assigned_to_user_id: string | null
  assigned_user?: { id: string; name: string; email: string } | null
  assigned_at: string | null
  notes: string | null
  reference: string | null
  created_at: string
  updated_at: string
}

export type UserStatus = 'invited' | 'active' | 'suspended' | 'deactivated'
export type ScopeType = 'organization' | 'department' | 'location'

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
  home_department_id: string | null
  must_change_password: boolean
  last_login_at: string | null
  roles?: { id: string; code: string; name: string }[]
  data_scopes?: DataScopeEntry[]
  created_at: string
  updated_at: string
}

/** An organization where the current administrator may add users, with its roles. */
export interface UserOrganizationOption {
  id: string
  code: string
  name: string
  is_current: boolean
  roles: { id: string; code: string; name: string; description: string | null; permissions_count: number; permissions?: string[] }[]
}

export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked'

/** An invitation of the current organization, as its administrators see it (never the token). */
export interface OrganizationInvitation {
  id: string
  organization_id: string
  email: string
  status: InvitationStatus
  roles?: { id: string; code: string; name: string }[]
  scopes: DataScopeEntry[]
  employee_number: string | null
  job_title: string | null
  home_department_id: string | null
  license_code_id: string | null
  license_code?: { id: string; code: string } | null
  invited_by?: { id: string; name: string } | null
  expires_at: string
  last_sent_at: string | null
  send_count: number
  accepted_at: string | null
  revoked_at: string | null
  created_at: string | null
}

/** "log": the server only writes emails to its log (development); nothing reaches the recipient. */
export type EmailDelivery = 'mail' | 'log'

/** First administrator of an organization created on the platform console (GET /platform/organizations/{id}/admin-invitation). */
export interface AdminOnboarding {
  has_members: boolean
  invitation: OrganizationInvitation | null
}

/** What the holder of an invitation token is invited to (POST /auth/invitation/inspect). */
export interface InvitationPreview {
  email: string
  organization: OrganizationSummary
  invited_by: string
  roles: { code: string; name: string }[]
  expires_at: string
}

/** A user's membership in one organization (only organizations the viewer may see). */
export interface UserOrganizationAccess {
  id: string
  code: string
  name: string
  is_current: boolean
  status: UserStatus
  roles: { id: string; code: string; name: string }[]
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

export interface OrganizationSummary {
  id: string
  code: string
  name: string
}

export interface Profile {
  user: {
    id: string
    name: string
    email: string
    /** Set once the owner followed the verification link; required to accept invitations. */
    email_verified_at: string | null
    user_type: 'tenant' | 'platform'
    job_title: string | null
    must_change_password: boolean
    last_login_at: string | null
  }
  /** Active organization of this session (null: the user must choose one). */
  organization: Organization | null
  /** Every organization this account can work in (same login for all of them). */
  organizations: OrganizationSummary[]
  permissions: string[]
  data_scope: { organization_wide: boolean; department_ids: string[]; location_ids: string[] } | null
}

// ---------------------------------------------------------------- Master data

export type MasterStatus = 'active' | 'inactive' | 'archived'
export type VersionStatus = 'draft' | 'released' | 'deprecated'

/** Compact `{id, code, name}` reference embedded in other resources. */
export interface Ref {
  id: string
  code: string
  name: string
}

/** Fields shared by every master record. */
export interface MasterRecord {
  id: string
  code: string
  name: string
  status: MasterStatus
  created_at: string
  updated_at: string
}

export type Department = MasterRecord

export interface Location extends MasterRecord {
  address: string | null
  parent_id: string | null
  parent?: Ref | null
  location_type_id: string
  location_type?: Ref | null
  depth: number
}

/** Units, location types, transaction types. */
export interface ReferenceItem extends MasterRecord {
  symbol?: string
  quantity?: string | null
  description?: string | null
  sort_order?: number
}

/** Location type as seen by an organization: built-in (read-only) or its own. */
export interface LocationType extends MasterRecord {
  description: string | null
  is_built_in: boolean
}

export interface GenericCategory extends MasterRecord {
  description: string | null
  parent_id: string | null
  parent?: Ref | null
  depth: number
  sort_order: number
  definitions_count?: number
}

export interface TenantCategory extends MasterRecord {
  description: string | null
  parent_id: string | null
  parent?: Ref | null
  generic_category_id: string
  generic_category?: Ref | null
  depth: number
  /** Custom fields defined on the category itself (inherited ones not counted). */
  attributes_count?: number
}

/** Custom field of an asset category; subcategories inherit it. Key and type never change. */
export interface CategoryAttribute {
  id: string
  key: string
  label: string
  data_type: CategoryAttributeType
  is_required: boolean
  options: AttributeOption[] | null
  category_id: string
  category?: Ref | null
  sort_order: number
  status: 'active' | 'archived'
  is_default: boolean
  section: 'basic_information' | 'location_ownership' | 'status_condition' | null
}

export type CategoryAttributeType = Exclude<AttributeDataType, 'multi_enum'>

export type AttributeDataType = 'string' | 'text' | 'integer' | 'decimal' | 'boolean' | 'date' | 'enum' | 'multi_enum'
export type AttributeValue = string | number | boolean | string[] | null

export interface AttributeOption {
  value: string
  label: string
}

export interface AttributeValidation {
  min?: number | string
  max?: number | string
  min_length?: number
  max_length?: number
  pattern?: string
  decimals?: number
}

export interface UnitSpec {
  id: string
  code: string
  symbol: string
}

export interface AttributeDefinition {
  id: string
  key: string
  label: string
  help_text: string | null
  data_type: AttributeDataType
  is_required: boolean
  validation: AttributeValidation
  options: AttributeOption[] | null
  unit_id: string | null
  unit?: UnitSpec | null
  default_value: AttributeValue
  is_searchable: boolean
  is_controlled: boolean
  overridable: OverridableProperty[]
  sort_order: number
}

export type OverridableProperty = 'label' | 'help_text' | 'required' | 'options' | 'validation' | 'default_value'

export interface VersionRef {
  id: string
  version_number: number
  released_at?: string | null
  status?: VersionStatus
}

export interface GenericVersion {
  id: string
  definition_id: string
  definition?: Ref & { status: MasterStatus }
  version_number: number
  status: VersionStatus
  changelog: string | null
  migration_notes: string | null
  schema_hash: string | null
  released_at: string | null
  deprecated_at: string | null
  attributes_count?: number
  attributes?: AttributeDefinition[]
  created_at: string
  updated_at: string
}

export interface GenericDefinition extends MasterRecord {
  description: string | null
  category_id: string
  category?: Ref | null
  current_version?: VersionRef | null
  versions?: GenericVersion[]
}

export interface AttributeOverride {
  generic_attribute_key: string
  overrides: Partial<{
    label: string
    help_text: string | null
    required: boolean
    options: string[]
    validation: AttributeValidation
    default_value: AttributeValue
  }>
}

/** One attribute of an effective schema (generic + overrides + tenant attributes). */
export interface EffectiveAttribute {
  key: string
  label: string
  help_text: string | null
  data_type: AttributeDataType
  required: boolean
  validation: AttributeValidation
  options: AttributeOption[] | null
  unit: UnitSpec | null
  default_value: AttributeValue
  searchable: boolean
  controlled: boolean
  sort_order: number
  source: 'generic' | 'tenant'
  overridden: OverridableProperty[]
}

export interface EffectiveSchema {
  schema_version: number
  generic: { definition_id: string; definition_code: string; version_id: string; version_number: number }
  tenant: { definition_id: string; definition_code: string; version_id: string; version_number: number }
  attributes: EffectiveAttribute[]
}

export interface TenantVersion {
  id: string
  tenant_definition_id: string
  definition?: Ref & { status: MasterStatus }
  version_number: number
  status: VersionStatus
  changelog: string | null
  generic_definition_version_id: string
  generic_version?: { id: string; version_number: number; status: VersionStatus }
  schema_hash: string | null
  effective_schema?: EffectiveSchema | null
  released_at: string | null
  deprecated_at: string | null
  attributes?: AttributeDefinition[]
  overrides?: AttributeOverride[]
  created_at: string
  updated_at: string
}

export interface TenantDefinition extends MasterRecord {
  description: string | null
  generic_definition_id: string
  generic_definition?: Ref | null
  tenant_category_id: string | null
  category?: Ref | null
  current_version?: VersionRef | null
  draft_version?: VersionRef | null
  versions?: TenantVersion[]
}

export interface SchemaPreview {
  data: EffectiveSchema
  meta: { schema_hash: string; issues: string[]; frozen: boolean }
}
