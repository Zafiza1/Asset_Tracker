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
  asset_number: string
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

export interface CategoryAttribute {
  id: string
  key: string
  label: string
  data_type: string
  options: { value: string; label: string }[] | null
  sort_order: number
  section: 'basic_information' | 'location_ownership' | 'status_condition' | null
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
