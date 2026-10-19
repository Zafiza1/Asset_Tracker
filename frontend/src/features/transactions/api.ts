import type { AssetStatus, Ref } from '../assets/api'

/** Asset Transactions module: API shapes and labels. Types mirror App\Domain\AssetTransactions\TransactionType. */

export type TransactionType = 'registration' | 'transfer' | 'status_change' | 'loan' | 'return' | 'note' | 'archive' | 'restore'

/** Types recorded manually in the Transactions menu. */
export type ManualTransactionType = 'transfer' | 'status_change' | 'loan' | 'return' | 'note'

export interface AssetTransaction {
  id: string
  transaction_number: string
  type: TransactionType
  type_label: string
  transaction_date: string
  asset_id: string
  asset?: { id: string; asset_number: string; name: string }
  from_location?: Ref | null
  to_location?: Ref | null
  from_status: AssetStatus | null
  to_status: AssetStatus | null
  borrower_name: string | null
  due_date: string | null
  notes: string | null
  created_by?: string | null
  created_at: string
}

export const transactionTypeLabel: Record<TransactionType, string> = {
  registration: 'Penambahan aset',
  transfer: 'Pemindahan lokasi',
  status_change: 'Perubahan status',
  loan: 'Peminjaman',
  return: 'Pengembalian',
  note: 'Aktivitas lain',
  archive: 'Penonaktifan',
  restore: 'Pengaktifan kembali',
}

export const manualTypes: ManualTransactionType[] = ['transfer', 'status_change', 'loan', 'return', 'note']

/** Extra permission per type (the API checks it as well). */
export const typePermission: Record<ManualTransactionType, string | null> = {
  transfer: 'asset.transfer',
  status_change: 'asset.status_change',
  loan: 'asset.assign',
  return: 'asset.assign',
  note: null,
}

/** Types that make sense for the asset's current status (the API enforces the same rules). */
export function allowedTypes(status: AssetStatus | undefined): ManualTransactionType[] {
  if (!status) return manualTypes
  if (status === 'on_loan') return ['return', 'note']
  if (status === 'disposed') return ['status_change', 'note']
  if (status === 'under_maintenance') return ['transfer', 'status_change', 'note']
  return ['transfer', 'status_change', 'loan', 'note']
}
