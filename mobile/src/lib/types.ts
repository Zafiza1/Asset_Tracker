/** API shapes, mirroring frontend/src/features/assets/api.ts and lib/api/types.ts of the web app. */

export type AssetStatus = 'in_use' | 'available' | 'under_maintenance' | 'on_loan' | 'lost' | 'disposed'

export type AttributeValue = string | number | boolean | string[] | null

export interface Ref {
  id: string
  code: string
  name: string
}

export interface Asset {
  id: string
  serial_number: string | null
  name: string
  category_id: string
  category?: Ref | null
  barcode_enabled: boolean
  location_id: string | null
  location?: Ref | null
  status: AssetStatus
  acquisition_date: string | null
  acquisition_cost: string | null
  description: string | null
  /** Category field values keyed by attribute key. */
  attributes: Record<string, AttributeValue>
  /** Path under the server (/api/assets/{id}/image?v=...), or null when there is no photo. */
  image_url: string | null
  borrower_name: string | null
  loan_due_date: string | null
  is_archived: boolean
  archived_at: string | null
  created_by?: string | null
  created_at: string
  updated_at: string
}

export type AttributeType = 'string' | 'text' | 'integer' | 'decimal' | 'boolean' | 'date' | 'enum' | 'multi_enum'

export interface CategoryAttribute {
  id: string
  key: string
  label: string
  data_type: AttributeType | string
  options: { value: string; label: string }[] | null
  sort_order: number
  section: 'basic_information' | 'location_ownership' | 'status_condition' | 'specific_attributes' | null
  /** Only on GET /tenant-masters/categories/{id}/attributes (the form's fields). */
  is_required?: boolean
  is_default?: boolean
}

/** Row of a master list (locations, categories) used in pickers; depth is its level in the tree. */
export interface MasterOption {
  id: string
  code: string
  name: string
  depth?: number
  status: string
}

/** Statuses an asset can be registered with (AssetStatus::INITIAL). */
export const INITIAL_STATUSES: AssetStatus[] = ['in_use', 'available', 'under_maintenance']

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

/** Approval request (Notifications menu on the web); details, impact and history only on the detail endpoint. */
export interface ApprovalRequest {
  id: string
  category: 'transaction' | 'asset' | 'master'
  category_label: string
  action: string
  action_label: string
  summary: string
  status: ApprovalStatus
  requester?: { id: string; name: string } | null
  decider?: { id: string; name: string } | null
  decided_at: string | null
  decision_note: string | null
  details?: { label: string; value: string; current?: string }[]
  impact?: { text: string; destructive: boolean }
  asset_id?: string | null
  history?: { action: string; label: string; actor: string | null; note: string | null; at: string | null }[]
  created_at: string
}

export type ApprovalCounts = Record<ApprovalStatus | 'all', number>

export const approvalStatusLabel: Record<ApprovalStatus, string> = {
  pending: 'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
}

export const approvalStatusColors: Record<ApprovalStatus, [string, string]> = {
  pending: ['#fef3c7', '#92400e'],
  approved: ['#dcfce7', '#166534'],
  rejected: ['#fee2e2', '#991b1b'],
  cancelled: ['#f1f5f9', '#334155'],
}

export interface AssetDetail {
  data: Asset
  attribute_fields: CategoryAttribute[]
}

export interface AssetTransaction {
  id: string
  transaction_number: string
  type_label: string
  transaction_date: string
  notes: string | null
  created_by: string | null
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}

export interface Organization {
  id: string
  code: string
  name: string
}

export interface Profile {
  user: { id: string; name: string; email: string; job_title: string | null }
  organization: Organization
  permissions: string[]
  /** Optional modules switched on for the organization (e.g. 'barcode'). */
  modules?: string[]
}

export const assetStatusLabel: Record<AssetStatus, string> = {
  in_use: 'Aktif',
  available: 'Tidak Aktif',
  under_maintenance: 'Dalam Perbaikan',
  on_loan: 'Dipinjamkan',
  lost: 'Hilang',
  disposed: 'Dihapuskan',
}

/** Badge colors per status: [background, text], as the web app's badge tones. */
export const assetStatusColors: Record<AssetStatus, [string, string]> = {
  in_use: ['#dcfce7', '#166534'],
  available: ['#dbeafe', '#1e40af'],
  under_maintenance: ['#fef3c7', '#92400e'],
  on_loan: ['#fef3c7', '#92400e'],
  lost: ['#fee2e2', '#991b1b'],
  disposed: ['#f1f5f9', '#334155'],
}

export const SECTION_LABELS: Record<string, string> = {
  basic_information: 'Informasi Dasar',
  location_ownership: 'Lokasi & Kepemilikan',
  status_condition: 'Status & Kondisi',
  specific_attributes: 'Atribut Khusus',
  other: 'Lainnya',
}
