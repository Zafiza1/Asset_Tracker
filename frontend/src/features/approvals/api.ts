import { useQuery } from '@tanstack/react-query'
import type { BadgeTone } from '../../components/ui/badgeTones'
import { api } from '../../lib/api/client'
import type { Resource } from '../../lib/api/types'

/** Notifications menu: approval requests (App\Domain\Approvals). */

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'
export type ApprovalCategory = 'transaction' | 'asset' | 'master'

export interface ApprovalRequest {
  id: string
  category: ApprovalCategory
  category_label: string
  action: string
  action_label: string
  summary: string
  status: ApprovalStatus
  requester?: { id: string; name: string } | null
  decider?: { id: string; name: string } | null
  decided_at: string | null
  decision_note: string | null
  /** Readable submitted input; only on the detail endpoint. */
  details?: { label: string; value: string }[]
  created_at: string
}

export const approvalStatusLabel: Record<ApprovalStatus, string> = {
  pending: 'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
}

export const approvalStatusTone: Record<ApprovalStatus, BadgeTone> = {
  pending: 'amber',
  approved: 'green',
  rejected: 'red',
  cancelled: 'slate',
}

export const approvalCategoryLabel: Record<ApprovalCategory, string> = {
  transaction: 'Transaksi',
  asset: 'Aset',
  master: 'Master Data',
}

/** Every approval query starts with this key, so one invalidation refreshes list, detail and badge. */
export const APPROVALS_KEY = ['/approvals'] as const

/** Pending requests to look at (all of them for approvers, the user's own otherwise); polled for the badge. */
export function useApprovalSummary(enabled = true) {
  return useQuery({
    queryKey: [...APPROVALS_KEY, 'summary'],
    queryFn: () => api.get<Resource<{ pending: number; can_decide: boolean }>>('/approvals/summary'),
    refetchInterval: 60_000,
    enabled,
  })
}
