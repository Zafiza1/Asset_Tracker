import { api } from '../../lib/api/client'

/** Card keys in the server's default order (HomeCards::CARDS). */
export const HOME_CARD_KEYS = [
  'decisions',
  'my_requests',
  'my_assets',
  'overdue_outgoing',
  'overdue_loans',
  'due_soon',
  'date_reminders',
  'incomplete_assets',
  'inactive_custodians',
  'license',
  'invitations',
  'users',
  'my_reports',
  'recent_activity',
] as const

export type HomeCardKey = (typeof HOME_CARD_KEYS)[number]

interface AssetRef {
  id: string
  name: string | null
}

export interface HomeCardData {
  /** Steps of transactions and data change requests waiting for the user; a part they may not see is null. */
  decisions: {
    count: number
    overdue: number
    tasks: {
      count: number
      overdue: number
      items: { run_id: string; transaction_number: string | null; type: string | null; kind: string; step: string | null; started_by: string | null; due_date: string | null; overdue: boolean }[]
    } | null
    approvals: { count: number; items: { id: string; summary: string; action: string; requested_by: string | null; created_at: string | null }[] } | null
  }
  my_requests: {
    days: number
    counts: Record<'pending' | 'approved' | 'rejected' | 'cancelled', number> & Record<string, number>
    items: { id: string; summary: string; status: string; decision_note: string | null; decided_by: string | null; created_at: string | null }[]
  }
  my_assets: { count: number; items: AssetRef[] }
  overdue_outgoing: { count: number; items: { transaction_number: string; asset: AssetRef; counterparty_name: string | null; due_date: string | null; days_late: number }[] }
  overdue_loans: { count: number; items: { asset: AssetRef; borrower_name: string | null; due_date: string | null; days_late: number }[] }
  due_soon: { days: number; count: number; items: { kind: 'loan' | 'outgoing'; asset: AssetRef; party: string | null; due_date: string | null }[] }
  date_reminders: { days: number; count: number; items: { asset: AssetRef; label: string; due_date: string }[] }
  incomplete_assets: { count: number; counts: { no_serial_number: number; missing_required_attribute: number; no_location: number } }
  inactive_custodians: { count: number; items: { asset: AssetRef; custodian: string | null }[] }
  license: { active: boolean; expires_at: string | null; days_left: number | null; capacity: number | null; used: number; over_limit: boolean }
  invitations: { pending: number; expired: number }
  users: { days: number; new: number; deactivated: number }
  my_reports: { items: { id: string; export_number: string; format: string; template: string | null; downloadable: boolean; created_at: string | null }[] }
  recent_activity: { count: number; items: { action: string; actor: string | null; created_at: string | null }[] }
}

export type HomeCard = { [K in HomeCardKey]: { key: K } & HomeCardData[K] }[HomeCardKey]

export interface HomePreferences {
  order: HomeCardKey[]
  hidden: HomeCardKey[]
}

export interface HomeCardsResponse {
  data: HomeCard[]
  meta: { refresh_seconds: number; preferences: HomePreferences }
}

export const HOME_CARDS_QUERY_KEY = ['/home/cards'] as const

export const homeApi = {
  cards: () => api.get<HomeCardsResponse>('/home/cards'),
  savePreferences: (preferences: HomePreferences) => api.put<{ data: HomePreferences }>('/home/preferences', preferences),
}

/**
 * The cards in the member's order: the ones they placed first, then any card they have not
 * placed yet (a new card, or one a new permission brought) in the server's order.
 */
export function arrangeCards<T extends { key: HomeCardKey }>(cards: T[], order: HomeCardKey[]): T[] {
  const rank = (key: HomeCardKey) => {
    const placed = order.indexOf(key)
    return placed === -1 ? order.length + HOME_CARD_KEYS.indexOf(key) : placed
  }
  return [...cards].sort((a, b) => rank(a.key) - rank(b.key))
}

/** "5 menit", "3 jam", "2 hari" since a timestamp. */
export function ago(iso: string | null, now = Date.now()): string {
  if (!iso) return ''
  const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60_000))
  if (minutes < 60) return minutes <= 1 ? 'baru saja' : `${minutes} menit`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} jam`
  return `${Math.floor(hours / 24)} hari`
}
