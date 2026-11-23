import { useQuery } from '@tanstack/react-query'
import { Workflow } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { PillTabs } from '../../components/ui/Tabs'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { formatDate } from '../../lib/format'
import { nodeTypeLabel, runStateTone, runsApi, type TransactionRun } from './api'

type Tab = 'mine_tasks' | 'running' | 'mine' | 'ended'
const TABS: { value: Tab; label: string }[] = [
  { value: 'mine_tasks', label: 'Menunggu saya' },
  { value: 'running', label: 'Berjalan' },
  { value: 'mine', label: 'Dimulai saya' },
  { value: 'ended', label: 'Selesai' },
]
const PARAMS: Record<Tab, Record<string, string>> = {
  mine_tasks: { assigned_to_me: '1', state: 'running' },
  running: { state: 'running' },
  mine: { mine: '1' },
  ended: { state: 'completed' },
}
const EMPTY: Record<Tab, string> = {
  mine_tasks: 'Tidak ada langkah yang menunggu Anda.',
  running: 'Tidak ada transaksi yang sedang berjalan.',
  mine: 'Anda belum memulai transaksi yang mengikuti alur.',
  ended: 'Belum ada transaksi beralur yang selesai.',
}

/** Transactions going through a flow (F-07 §5), "waiting for me" first. */
export function TransactionRunsPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tab = (TABS.some((t) => t.value === params.get('tab')) ? params.get('tab') : 'mine_tasks') as Tab
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['/transaction-runs', tab, page],
    queryFn: () => runsApi.list({ ...PARAMS[tab], page: String(page), per_page: '25' }),
    placeholderData: (prev) => prev,
  })
  const rows = query.data?.data ?? []

  const columns: Column<TransactionRun>[] = [
    {
      key: 'number',
      header: 'Transaksi',
      className: 'whitespace-normal',
      cell: (r) => (
        <div>
          <Link to={`/transaksi/berjalan/${r.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 hover:underline">
            {r.transaction_number}
          </Link>
          <span className="block text-sm text-slate-700">{r.type_definition?.name}</span>
        </div>
      ),
    },
    {
      key: 'state',
      header: 'Status',
      className: 'w-56 whitespace-normal',
      cell: (r) => (
        <div className="space-y-1">
          <Badge tone={runStateTone[r.state]}>{r.state_label}</Badge>
          {r.state === 'running' && r.current_step && <span className="block text-xs text-slate-600">{r.current_step.label ?? nodeTypeLabel[r.current_step.type]}</span>}
        </div>
      ),
    },
    { key: 'assets', header: 'Aset', className: 'w-56', hideOnMobile: true, cell: (r) => (r.assets ?? []).map((a) => a.name).join(', '), title: (r) => (r.assets ?? []).map((a) => a.name).join(', ') },
    {
      key: 'task',
      header: 'Langkah',
      className: 'w-44',
      hideOnMobile: true,
      cell: (r) =>
        r.open_task ? (
          <span className="text-xs text-slate-600">
            {r.open_task.can_act ? <span className="font-semibold text-amber-800">Giliran Anda</span> : (r.open_task.assignee_name ?? '—')}
            {r.open_task.due_date ? ` · batas ${formatDate(r.open_task.due_date)}` : ''}
          </span>
        ) : (
          <span className="text-xs text-slate-400">—</span>
        ),
    },
    { key: 'by', header: 'Dimulai', className: 'w-40', hideOnMobile: true, cell: (r) => <span className="text-xs text-slate-600">{r.created_by?.name} · {formatDate(r.created_at)}</span> },
  ]

  return (
    <>
      <PageHeader title="Transaksi Berjalan" description="Transaksi jenis organisasi yang mengikuti alur: langkah yang menunggu Anda, yang sedang berjalan, dan yang sudah selesai." />
      <div className="mb-4">
        <PillTabs label="Tampilkan" tabs={TABS} value={tab} onChange={(v) => (setParams({ tab: v }), setPage(1))} />
      </div>
      <Card>
        {query.isPending ? (
          <TableSkeleton columns={5} rows={6} />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState icon={Workflow} title={EMPTY[tab]} description="Transaksi beralur dimulai dari menu Transaksi dengan memilih jenis organisasi yang memiliki alur terbit." />
        ) : (
          <>
            <DataTable columns={columns} rows={rows} onRowClick={(r) => navigate(`/transaksi/berjalan/${r.id}`)} rowHref={(r) => `/transaksi/berjalan/${r.id}`} />
            {query.data?.meta && <Pagination meta={query.data.meta} onPage={setPage} />}
          </>
        )}
      </Card>
    </>
  )
}
