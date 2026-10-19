import type { BadgeTone } from '../../components/ui/badgeTones'

/** Asset Management module: API shapes and labels. Statuses mirror App\Domain\Assets\AssetStatus. */

export type AssetStatus = 'available' | 'in_use' | 'on_loan' | 'under_maintenance' | 'disposed'

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
  location_id: string | null
  location?: Ref | null
  status: AssetStatus
  acquisition_date: string | null
  acquisition_cost: string | null
  description: string | null
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
  available: 'Tersedia',
  in_use: 'Digunakan',
  on_loan: 'Dipinjam',
  under_maintenance: 'Dalam perbaikan',
  disposed: 'Dihapuskan',
}

export const assetStatusTone: Record<AssetStatus, BadgeTone> = {
  available: 'green',
  in_use: 'blue',
  on_loan: 'amber',
  under_maintenance: 'amber',
  disposed: 'slate',
}

/** Statuses selectable when registering an asset. */
export const initialStatuses: AssetStatus[] = ['available', 'in_use', 'under_maintenance']

const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return money.format(Number(value))
}
