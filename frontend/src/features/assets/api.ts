import type { BadgeTone } from '../../components/ui/badgeTones'
import type { AttributeValue, ModuleKey } from '../../lib/api/types'

/** Asset Management module: API shapes and labels. Statuses mirror App\Domain\Assets\AssetStatus. */

export type AssetStatus = 'in_use' | 'available' | 'under_maintenance' | 'on_loan' | 'off_site' | 'lost' | 'disposed'

export interface Ref {
  id: string
  code: string
  name: string
}

export interface Asset {
  id: string
  /** "No Reference": given by the system. */
  reference_number: string
  serial_number: string | null
  name: string
  category_id: string
  category?: Ref | null
  /** Modules picked for the asset in Menu Modul that the organization has switched on. */
  modules: ModuleKey[]
  /** Uses the Barcode module: it has printable 1D/2D barcodes. */
  barcode_enabled: boolean
  location_id: string | null
  location?: Ref | null
  /** F-09: the member who answers for the asset; kept (marked inactive) when they leave. */
  custodian_user_id: string | null
  custodian?: AssetCustodian | null
  department_id: string | null
  department?: Ref | null
  status: AssetStatus
  /** Where a cylinder is according to its transactions; null when it never was on one. */
  position?: AssetPosition | null
  /** Lokasi Status, set by the system from the cylinder's transactions. */
  location_status: LocationStatus
  acquisition_date: string | null
  acquisition_cost: string | null
  description: string | null
  /** Category field values keyed by attribute key. */
  attributes: Record<string, AttributeValue>
  /** Keys of optional category fields this asset does not use (RAM on a keyboard). */
  hidden_attributes: string[]
  /** Fields only this asset has. */
  extra_attributes: ExtraAttribute[]
  /** Same-origin URL of the photo (with a cache-busting version), or null when there is none. */
  image_url: string | null
  /** Same-origin URL to download the document, or null when there is none. */
  document_url: string | null
  document_name: string | null
  borrower_name: string | null
  loan_due_date: string | null
  is_archived: boolean
  archived_at: string | null
  lock_version: number
  created_by?: string | null
  created_at: string
  updated_at: string
}

export type LocationStatusKey = 'warehouse' | 'in_transit' | 'at_customer'

export interface LocationStatus {
  key: LocationStatusKey
  label: string
  /** Where exactly: the customer, or the delivery it is planned for or travelling with. */
  detail: string | null
  delivery_id: string | null
  delivery_number: string | null
}

/** Mirrors Fleet::LOCATION_STATUSES. */
export const locationStatusLabel: Record<LocationStatusKey, string> = {
  warehouse: 'Di Gudang',
  in_transit: 'Dalam Perjalanan',
  at_customer: 'Di Customer',
}

export interface AssetPosition {
  state: 'warehouse' | 'planned' | 'in_transit' | 'at_customer' | 'missing'
  label: string
  customer: string
  delivery_id: string
  delivery_number: string
}

/** Code of the standard category of cylinders (StandardCategories::CYLINDER). */
export const CYLINDER_CATEGORY = 'AST_TABUNG'

export interface AssetCustodian {
  id: string
  name: string | null
  is_active: boolean
}

/** An active member offered by /assets/custodian-options. */
export interface CustodianOption {
  id: string
  name: string
  email: string
  job_title: string | null
  home_department: Ref | null
}

/** Labels of an asset that is no cylinder; see statusLabel for one asset and statusFilterLabel for lists. */
export const assetStatusLabel: Record<AssetStatus, string> = {
  in_use: 'Aktif',
  available: 'Tersedia',
  under_maintenance: 'Dalam Perbaikan',
  on_loan: 'Dipinjamkan',
  off_site: 'Di Customer / Dikirim',
  lost: 'Hilang',
  disposed: 'Dihapuskan',
}

/** Lists mix cylinders and vehicles, so a filter names both meanings (AssetStatus::labels). */
export const statusFilterLabel: Record<AssetStatus, string> = { ...assetStatusLabel, available: 'Tersedia / Di Gudang' }

export function isCylinder(asset: { category?: Ref | null } | null | undefined): boolean {
  return asset?.category?.code === CYLINDER_CATEGORY
}

/** The status of one asset: a cylinder that is available is in the warehouse. */
export function statusLabel(status: AssetStatus, cylinder = false): string {
  return cylinder && status === 'available' ? 'Di Gudang' : assetStatusLabel[status]
}

/** A cylinder is in the warehouse or with a customer, never "in use" by the organization itself. */
export function withoutInUse(statuses: AssetStatus[], cylinder: boolean): AssetStatus[] {
  return cylinder ? statuses.filter((s) => s !== 'in_use') : statuses
}

export const assetStatusTone: Record<AssetStatus, BadgeTone> = {
  in_use: 'green',
  available: 'blue',
  under_maintenance: 'amber',
  on_loan: 'amber',
  off_site: 'amber',
  lost: 'red',
  disposed: 'slate',
}

/** Mirrors AssetController::MAX_EDIT_SELECTION: the checked ids travel in the download URL. */
export const MAX_EDIT_SELECTION = 100

/** Statuses selectable when registering an asset. */
export const initialStatuses: AssetStatus[] = ['in_use', 'available', 'under_maintenance']

const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return money.format(Number(value))
}

/** Types of a field only one asset has (CategoryAttributeService::EXTRA_TYPES). */
export type ExtraAttributeType = 'string' | 'integer' | 'decimal' | 'date' | 'boolean'

export const EXTRA_TYPE_LABEL: Record<ExtraAttributeType, string> = {
  string: 'Teks',
  integer: 'Angka bulat',
  decimal: 'Angka desimal',
  date: 'Tanggal',
  boolean: 'Ya/Tidak',
}

export interface ExtraAttribute {
  label: string
  data_type: ExtraAttributeType
  value: AttributeValue
}
