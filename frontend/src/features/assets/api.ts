import type { BadgeTone } from '../../components/ui/badgeTones'
import type { AttributeValue } from '../../lib/api/types'

/** Asset Management module: API shapes and labels. Statuses mirror App\Domain\Assets\AssetStatus. */

export type AssetStatus = 'in_use' | 'available' | 'under_maintenance' | 'on_loan' | 'lost' | 'disposed'

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
  /** The asset's default "Barcode" field is set to yes: it has printable 1D/2D barcodes. */
  barcode_enabled: boolean
  location_id: string | null
  location?: Ref | null
  status: AssetStatus
  acquisition_date: string | null
  acquisition_cost: string | null
  description: string | null
  /** Category field values keyed by attribute key. */
  attributes: Record<string, AttributeValue>
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

export const assetStatusLabel: Record<AssetStatus, string> = {
  in_use: 'Aktif',
  available: 'Tidak Aktif',
  under_maintenance: 'Dalam Perbaikan',
  on_loan: 'Dipinjamkan',
  lost: 'Hilang',
  disposed: 'Dihapuskan',
}

export const assetStatusTone: Record<AssetStatus, BadgeTone> = {
  in_use: 'green',
  available: 'blue',
  under_maintenance: 'amber',
  on_loan: 'amber',
  lost: 'red',
  disposed: 'slate',
}

/** Statuses selectable when registering an asset. */
export const initialStatuses: AssetStatus[] = ['in_use', 'available', 'under_maintenance']

const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return money.format(Number(value))
}
