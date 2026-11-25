/** API shapes, mirroring frontend/src/features/assets/api.ts and lib/api/types.ts of the web app. */

export type AssetStatus = 'in_use' | 'available' | 'under_maintenance' | 'on_loan' | 'off_site' | 'lost' | 'disposed'

export type AttributeValue = string | number | boolean | string[] | null

export interface Ref {
  id: string
  code: string
  name: string
}

export interface Asset {
  id: string
  /** "No Reference (dari sistem)". */
  reference_number: string
  serial_number: string | null
  name: string
  category_id: string
  category?: Ref | null
  /** Modules picked for the asset in Menu Modul that the organization has switched on. */
  modules: string[]
  /** Uses the Barcode module: it has printable 1D/2D barcodes. */
  barcode_enabled: boolean
  /** F-09: the member who answers for the asset; one who has since left stays, marked inactive. */
  custodian_user_id?: string | null
  custodian?: { id: string; name: string | null; is_active: boolean } | null
  department_id?: string | null
  department?: Ref | null
  /** Where a cylinder is according to its deliveries; null in the warehouse. */
  position?: { label: string; delivery_id: string; delivery_number: string } | null
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
  type?: string
  type_label: string
  transaction_date: string
  notes: string | null
  created_by: string | null
  /** Outgoing / incoming rows (F-02); null on other types. */
  reason_label?: string | null
  counterparty_name?: string | null
  counterparty_org?: string | null
  document_number?: string | null
  due_date?: string | null
  return_status?: 'Sudah kembali' | 'Terlambat' | 'Belum kembali' | null
  days_out?: number | null
}

// ------------------------------------------------------------------ deliveries (tabung)

export type DeliveryDirection = 'outgoing' | 'incoming'
export type DeliveryStatus = 'draft' | 'loading' | 'in_transit' | 'completed' | 'cancelled'
export type DeliveryStopStatus = 'pending' | 'arrived' | 'done' | 'skipped'
export type DeliveryItemState = 'planned' | 'loaded' | 'unloaded' | 'not_loaded' | 'returned' | 'missing' | 'cancelled'
export type DeliveryReportType = 'depart' | 'position' | 'issue' | 'resume' | 'arrive' | 'stop_done' | 'return' | 'completed'

export interface DeliveryPerson {
  id: string
  name: string
  phone?: string | null
}

export interface DeliveryStop {
  id: string
  sequence: number
  customer: { id: string; name: string; address?: string | null; phone?: string | null }
  status: DeliveryStopStatus
  status_label: string
  arrived_at: string | null
  done_at: string | null
  receiver_name: string | null
  note: string | null
}

export interface DeliveryItem {
  id: string
  stop_id: string | null
  asset_id: string
  name: string
  serial_number: string | null
  details?: Record<string, unknown>
  planned: boolean
  state: DeliveryItemState
  state_label: string
  loaded_at: string | null
  loaded_by: string | null
  unloaded_at: string | null
  unloaded_by: string | null
}

export interface DeliveryEvent {
  id: string
  type: string
  type_label: string
  note: string | null
  stop_id: string | null
  customer: string | null
  asset: string | null
  actor: string | null
  position: { latitude: number; longitude: number; accuracy: number | null; type: string; recorded_at: string } | null
  recorded_at: string
}

export interface Delivery {
  id: string
  number: string
  direction: DeliveryDirection
  direction_label: string
  status: DeliveryStatus
  status_label: string
  planned_date: string
  expected_return_date: string | null
  notes: string | null
  vehicle?: { id: string; name: string; plate: string | null } | null
  driver?: DeliveryPerson | null
  helper?: DeliveryPerson | null
  created_by?: string | null
  departed_at: string | null
  returned_at: string | null
  ended_at: string | null
  end_note: string | null
  stops?: DeliveryStop[]
  counts?: { total: number; loaded: number; unloaded: number }
  last_event?: DeliveryEvent | null
  last_position?: { latitude: number; longitude: number; accuracy: number | null; type: string; recorded_at: string } | null
  items?: DeliveryItem[]
  events?: DeliveryEvent[]
}

export interface DeliveryOptions {
  vehicles: { id: string; name: string; plate: string | null; on_trip: boolean }[]
  drivers: { id: string; name: string; phone?: string | null; position?: string; on_trip: boolean }[]
  helpers: { id: string; name: string; phone?: string | null; position?: string; on_trip: boolean }[]
  customers: { id: string; code: string; name: string; address?: string | null }[]
  return_days: number
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
  /** Token abilities beyond reading (e.g. 'transaction:write'); missing on logins before F-03. */
  abilities?: string[]
}

/** Code of the category Aset Tabung (StandardCategories::CYLINDER). */
export const CYLINDER_CATEGORY = 'AST_TABUNG'

export const assetStatusLabel: Record<AssetStatus, string> = {
  in_use: 'Aktif',
  available: 'Tersedia',
  under_maintenance: 'Dalam Perbaikan',
  on_loan: 'Dipinjamkan',
  off_site: 'Di Luar Organisasi',
  lost: 'Hilang',
  disposed: 'Dihapuskan',
}

/** Badge colors per status: [background, text], as the web app's badge tones. */
export const assetStatusColors: Record<AssetStatus, [string, string]> = {
  in_use: ['#dcfce7', '#166534'],
  available: ['#dbeafe', '#1e40af'],
  under_maintenance: ['#fef3c7', '#92400e'],
  on_loan: ['#fef3c7', '#92400e'],
  off_site: ['#fef3c7', '#92400e'],
  lost: ['#fee2e2', '#991b1b'],
  disposed: ['#f1f5f9', '#334155'],
}

export const SECTION_LABELS: Record<string, string> = {
  basic_information: 'Informasi Dasar',
  location_ownership: 'Posisi & Kepemilikan',
  status_condition: 'Status & Kondisi',
  specific_attributes: 'Atribut Khusus',
  other: 'Lainnya',
}
