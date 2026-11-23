import type { Option } from '@/components/form'
import type { Asset, AssetStatus, Profile } from './types'

/**
 * Outgoing and incoming transactions from the scanner (F-03). Rules mirror the API
 * (AssetTransactionService); the API re-checks everything.
 */
export type MobileTransactionType = 'outgoing' | 'incoming'
export type OutgoingNature = 'temporary' | 'permanent'

/** Token ability issued at login (MobileAccessToken::TRANSACTION_WRITE). */
export const TRANSACTION_WRITE = 'transaction:write'

const TYPE_PERMISSION: Record<MobileTransactionType, string> = { outgoing: 'asset.outgoing', incoming: 'asset.incoming' }

export const TYPE_LABEL: Record<MobileTransactionType, string> = { outgoing: 'Keluar', incoming: 'Masuk' }

/** Whether this login may record the type: token ability plus the permissions the route checks. */
export function canRecord(profile: Profile | null, can: (p: string) => boolean, type: MobileTransactionType): boolean {
  return !!profile?.abilities?.includes(TRANSACTION_WRITE) && can('transaction.create') && can(TYPE_PERMISSION[type])
}

/** Reasons per nature (OutgoingReason::REASONS). */
export const OUTGOING_REASONS: Record<OutgoingNature, Option[]> = {
  temporary: [
    { value: 'lent_external', label: 'Dipinjamkan ke pihak luar' },
    { value: 'external_repair', label: 'Perbaikan di vendor' },
    { value: 'sent_to_project', label: 'Dikirim ke proyek/klien' },
    { value: 'other', label: 'Lainnya' },
  ],
  permanent: [
    { value: 'sold', label: 'Dijual' },
    { value: 'donated', label: 'Dihibahkan' },
    { value: 'returned_to_owner', label: 'Dikembalikan ke pemilik/vendor' },
    { value: 'other', label: 'Lainnya' },
  ],
}

const OUTGOING_STATUSES: AssetStatus[] = ['in_use', 'available']

/** Why a scanned asset cannot be part of the transaction, or null when it can. */
export function rejectReason(asset: Pick<Asset, 'status' | 'is_archived'>, type: MobileTransactionType): string | null {
  if (asset.is_archived) return 'Aset nonaktif'
  if (type === 'outgoing') {
    if (asset.status === 'off_site') return 'Sudah di luar organisasi'
    if (!OUTGOING_STATUSES.includes(asset.status)) return 'Hanya aset Aktif atau Tersedia yang dapat dikeluarkan'
    return null
  }
  return asset.status === 'off_site' ? null : 'Tidak sedang di luar organisasi'
}

/** Today in the device's time zone as YYYY-MM-DD. */
export function localToday(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Whole days from `from` to `to` (both YYYY-MM-DD). */
export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000)
}

export interface OutgoingForm {
  outgoing_nature: OutgoingNature
  reason_code: string
  counterparty_name: string
  counterparty_org: string
  counterparty_contact: string
  due_date: string
  document_number: string
  notes: string
}

export interface IncomingForm {
  to_status: 'available' | 'under_maintenance'
  to_location_id: string
  counterparty_name: string
  document_number: string
  notes: string
}

export const emptyOutgoing = (): OutgoingForm => ({
  outgoing_nature: 'temporary',
  reason_code: '',
  counterparty_name: '',
  counterparty_org: '',
  counterparty_contact: '',
  due_date: '',
  document_number: '',
  notes: '',
})

export const emptyIncoming = (): IncomingForm => ({ to_status: 'available', to_location_id: '', counterparty_name: '', document_number: '', notes: '' })

/** Checks made before sending (the API repeats them); keys are the API's input names. */
export function validateOutgoing(form: OutgoingForm, today = localToday()): Record<string, string> {
  const errors: Record<string, string> = {}
  if (!form.reason_code) errors.reason_code = 'Pilih alasan keluar.'
  if (!form.counterparty_name.trim()) errors.counterparty_name = 'Nama pihak luar wajib diisi.'
  if (form.reason_code === 'other' && !form.notes.trim()) errors.notes = 'Keterangan wajib diisi untuk alasan "Lainnya".'
  if (form.outgoing_nature === 'temporary') {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.due_date)) errors.due_date = 'Wajib diisi untuk keluar sementara (TTTT-BB-HH).'
    else if (form.due_date < today) errors.due_date = 'Rencana kembali tidak boleh sebelum hari ini.'
  }
  return errors
}

/**
 * Request body of POST /transactions. The date is left out: the API records today
 * (back-dating is done on the web, F-02 §9a).
 */
export function payload(type: MobileTransactionType, assetIds: string[], form: OutgoingForm | IncomingForm): Record<string, unknown> {
  const body: Record<string, unknown> = { asset_ids: assetIds, type }
  for (const [k, v] of Object.entries(form)) {
    const value = typeof v === 'string' ? v.trim() : v
    if (value !== '') body[k] = value
  }
  if (type === 'outgoing' && (form as OutgoingForm).outgoing_nature === 'permanent') delete body.due_date
  return body
}
