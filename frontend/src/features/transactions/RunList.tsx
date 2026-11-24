import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Inbox } from 'lucide-react'
import { Fragment } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Card } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { formatDateTime } from '../../lib/format'
import { runsApi, runStateTone, type TransactionRun } from '../transactionTypes/api'

/**
 * Transactions with a flow (runs) as a list of links to their detail; `line` says where each one
 * stands. `bare` leaves out the card, for a list inside another one.
 */
export function RunList({ params, empty, line, bare = false }: { params: Record<string, string>; empty: string; line: (r: TransactionRun) => string; bare?: boolean }) {
  const runs = useQuery({ queryKey: ['/transaction-runs', 'center', params], queryFn: () => runsApi.list({ ...params, per_page: '50' }) })
  const Wrap = bare ? Fragment : Card
  if (runs.isPending) return <TableSkeleton rows={4} columns={3} />
  if (runs.isError) return <ErrorState error={runs.error} onRetry={() => runs.refetch()} />
  if (runs.data.data.length === 0) {
    return (
      <Wrap>
        <EmptyState icon={Inbox} title={empty} />
      </Wrap>
    )
  }
  return (
    <Wrap>
      <ul className="divide-y divide-slate-100">
        {runs.data.data.map((r) => (
          <li key={r.id}>
            <Link to={`/transaksi/berjalan/${r.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-sm font-semibold text-slate-900">{r.transaction_number}</span>
                  <span className="text-sm text-slate-700">{r.type_definition?.name}</span>
                  <Badge tone={runStateTone[r.state]}>{r.state_label}</Badge>
                </span>
                <span className="block truncate text-sm text-slate-600">{line(r)}</span>
                <span className="block truncate text-xs text-slate-500">
                  {(r.assets ?? []).map((a) => a.name).join(', ')}
                  {r.created_by ? ` · diajukan ${r.created_by.name}` : ''} · {formatDateTime(r.created_at)}
                </span>
              </span>
              <ArrowRight className="size-4 shrink-0 text-slate-400" aria-hidden />
            </Link>
          </li>
        ))}
      </ul>
    </Wrap>
  )
}
