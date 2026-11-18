import { useQuery } from '@tanstack/react-query'
import { ListChecks } from 'lucide-react'
import { Link } from 'react-router'
import { formatDate } from '../../lib/format'
import { localToday } from '../transactions/api'
import { nodeTypeLabel, runsApi } from './api'

/**
 * "Langkah transaksi menunggu saya" (F-07 §5) on the landing page: shown only while some step
 * waits for the user, so it never adds an empty box.
 */
export function TaskInboxCard() {
  const query = useQuery({
    queryKey: ['/transaction-runs', 'inbox'],
    queryFn: () => runsApi.list({ assigned_to_me: '1', state: 'running', per_page: '5' }),
    refetchInterval: 60_000,
  })
  const rows = query.data?.data ?? []
  if (rows.length === 0) return null
  const total = query.data?.meta.total ?? rows.length
  const today = localToday()

  return (
    <section className="mb-6 rounded-xl bg-amber-50/60 p-4 ring-1 ring-amber-200" aria-labelledby="task-inbox">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 id="task-inbox" className="flex items-center gap-2 text-base font-semibold text-slate-900">
          <ListChecks className="size-5 text-amber-700" aria-hidden />
          Langkah transaksi menunggu Anda
          <span className="rounded-full bg-amber-500 px-2 text-xs font-semibold leading-5 text-white">{total}</span>
        </h2>
        <Link to="/transaksi/berjalan" className="text-sm font-semibold text-brand-700 hover:underline">
          Lihat semua
        </Link>
      </div>
      <ul className="divide-y divide-amber-100 rounded-lg bg-white ring-1 ring-amber-100">
        {rows.map((r) => {
          const due = r.open_task?.due_date
          return (
            <li key={r.id}>
              <Link to={`/transaksi/berjalan/${r.id}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm hover:bg-amber-50">
                <span className="min-w-0">
                  <span className="font-mono text-xs font-semibold text-slate-900">{r.transaction_number}</span>
                  <span className="text-slate-700"> · {r.type_definition?.name}</span>
                  <span className="block text-xs text-slate-500">
                    {r.current_step?.label ?? (r.current_step ? nodeTypeLabel[r.current_step.type] : '')} · dimulai {r.created_by?.name}
                  </span>
                </span>
                {due && <span className={due < today ? 'text-xs font-semibold text-red-700' : 'text-xs text-slate-500'}>{due < today ? 'Lewat batas' : 'Batas'} {formatDate(due)}</span>}
              </Link>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
