import { useApprovalSummary } from '../features/approvals/api'
import { useTaskCount } from '../features/transactionTypes/hooks'
import { useAuth } from '../lib/auth/context'

/**
 * What waits for the member's decision in Persetujuan: steps of transactions (with
 * transaction.view) and, for approvers, data change requests. The menu and header badge.
 */
export function useInboxCount(): number {
  const { can } = useAuth()
  const approver = can('approval.manage')
  const tasks = useTaskCount(can('transaction.view')).data ?? 0
  const summary = useApprovalSummary(approver).data?.data
  return tasks + (approver && summary?.can_decide ? summary.pending : 0)
}
