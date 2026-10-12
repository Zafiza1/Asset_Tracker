import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Boxes, CalendarPlus, History, Wallet } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Badge } from '../../components/ui/Badge'
import { Card, PageHeader } from '../../components/ui/Card'
import { ErrorState, Spinner } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { formatDate, formatDateTime } from '../../lib/format'
import { assetStatusLabel, assetStatusTone, formatMoney, type AssetStatus } from '../assets/api'
import { transactionTypeLabel, type TransactionType } from '../transactions/api'
import { BarList, TrendChart, type TrendPoint } from './charts'

type AttentionReason = 'overdue_loan' | 'poor_condition' | 'under_maintenance' | 'no_location'

export interface Dashboard {
  generated_at: string
  summary: { total: number; archived: number; total_value: string; added_this_month: number; currency: string }
  by_status: { status: AssetStatus; label: string; count: number }[]
  by_category: { id: string; name: string; count: number }[]
  by_location: { id: string | null; name: string; count: number }[]
  by_condition: { id: string | null; name: string; severity: number | null; count: number }[]
  transactions: {
    days: number
    total: number
    by_type: { type: TransactionType; label: string; count: number }[]
    recent: { id: string; transaction_number: string; type: TransactionType; transaction_date: string; asset: { id: string; asset_number: string; name: string }; created_by: string | null }[]
  }
  trend: TrendPoint[]
  attention: {
    counts: Record<AttentionReason, number>
    items: { reason: AttentionReason; asset: { id: string; asset_number: string; name: string }; status: AssetStatus; location: string | null; condition: string | null; borrower_name: string | null; loan_due_date: string | null }[]
  }
}

const attentionLabel: Record<AttentionReason, string> = {
  overdue_loan: 'Peminjaman lewat batas',
  poor_condition: 'Kondisi buruk',
  under_maintenance: 'Dalam perbaikan',
  no_location: 'Belum ada lokasi',
}

/**
 * Asset Analytics module: everything here is computed by the API from the stored data,
 * within the user's data scope. Each widget has its own error boundary.
 */
export function DashboardPage() {
  const { profile } = useAuth()
  const query = useQuery({ queryKey: ['/dashboard'], queryFn: () => api.get<{ data: Dashboard }>('/dashboard') })

  return (
    <>
      <PageHeader
        title="Dashboard Aset"
        description={profile?.organization?.name}
        actions={query.data && <span className="self-end text-xs text-slate-500">Data per {formatDateTime(query.data.data.generated_at)}</span>}
      />
      {query.isLoading ? <Spinner /> : query.isError ? <ErrorState error={query.error} /> : <DashboardBody data={query.data!.data} />}
    </>
  )
}

function DashboardBody({ data }: { data: Dashboard }) {
  if (data.summary.total === 0 && data.summary.archived === 0) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
          <Boxes className="size-10 text-slate-400" aria-hidden />
          <p className="font-medium text-slate-800">Belum ada aset terdaftar</p>
          <p className="max-w-md text-sm text-slate-500">Analisis muncul otomatis setelah aset ditambahkan. Mulai dari menu Pendataan Aset.</p>
          <Link to="/aset" className="mt-2 text-sm font-medium text-brand-700 hover:underline">
            Buka Pendataan Aset
          </Link>
        </div>
      </Card>
    )
  }

  const attentionTotal = Object.values(data.attention.counts).reduce((a, b) => a + b, 0)

  return (
    <div className="space-y-4">
      <section aria-label="Ringkasan" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile icon={Boxes} label="Aset aktif" value={data.summary.total.toLocaleString('id-ID')} note={data.summary.archived ? `${data.summary.archived} aset nonaktif tidak dihitung` : undefined} to="/aset" />
        <StatTile icon={Wallet} label="Total nilai perolehan" value={formatMoney(data.summary.total_value)} note="Aset aktif yang nilainya diisi" />
        <StatTile icon={CalendarPlus} label="Ditambahkan bulan ini" value={data.summary.added_this_month.toLocaleString('id-ID')} />
        <StatTile icon={History} label={`Transaksi ${data.transactions.days} hari terakhir`} value={data.transactions.total.toLocaleString('id-ID')} to="/transaksi" />
      </section>

      <div className="grid gap-4 lg:grid-cols-3">
        <Widget title="Penambahan & perubahan aset (12 bulan)" className="lg:col-span-2">
          <TrendChart data={data.trend} />
        </Widget>
        <Widget title={`Perlu perhatian${attentionTotal ? ` (${attentionTotal})` : ''}`}>
          <Attention data={data.attention} />
        </Widget>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Widget title="Per kategori">
          <BarList data={data.by_category.map((c) => ({ key: c.id, label: c.name, value: c.count, to: `/aset?category_ids=${c.id}` }))} />
        </Widget>
        <Widget title="Per lokasi">
          <BarList data={data.by_location.map((l) => ({ key: l.id ?? 'none', label: l.name, value: l.count, to: l.id ? `/aset?location_id=${l.id}` : undefined }))} />
        </Widget>
        <Widget title="Per status">
          <BarList data={data.by_status.map((s) => ({ key: s.status, label: s.label, value: s.count, to: `/aset?status=${s.status}` }))} />
        </Widget>
        <Widget title="Per kondisi">
          <BarList data={data.by_condition.map((c) => ({ key: c.id ?? 'none', label: c.name, value: c.count, to: c.id ? `/aset?condition_id=${c.id}` : undefined }))} />
        </Widget>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Widget title={`Transaksi per jenis (${data.transactions.days} hari)`}>
          <BarList unit="transaksi" emptyText="Belum ada transaksi dalam periode ini." data={data.transactions.by_type.filter((t) => t.count > 0).map((t) => ({ key: t.type, label: t.label, value: t.count, to: `/transaksi?type=${t.type}` }))} />
        </Widget>
        <Widget title="Transaksi terbaru" className="lg:col-span-2" action={<Link to="/transaksi" className="text-sm font-medium text-brand-700 hover:underline">Lihat semua</Link>}>
          {data.transactions.recent.length === 0 ? (
            <p className="p-4 text-sm text-slate-500">Belum ada transaksi.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {data.transactions.recent.map((t) => (
                <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                  <div className="min-w-0">
                    <Link to={`/aset/${t.asset.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                      {t.asset.name}
                    </Link>
                    <p className="text-xs text-slate-500">
                      {transactionTypeLabel[t.type]} · {formatDate(t.transaction_date)}
                      {t.created_by && ` · ${t.created_by}`}
                    </p>
                  </div>
                  <span className="font-mono text-xs text-slate-500">{t.transaction_number}</span>
                </li>
              ))}
            </ul>
          )}
        </Widget>
      </div>
    </div>
  )
}

function Attention({ data }: { data: Dashboard['attention'] }) {
  const reasons = (Object.keys(data.counts) as AttentionReason[]).filter((r) => data.counts[r] > 0)
  if (reasons.length === 0) return <p className="p-4 text-sm text-slate-500">Tidak ada aset yang memerlukan tindak lanjut.</p>

  return (
    <div>
      <ul className="flex flex-wrap gap-2 border-b border-slate-100 p-4">
        {reasons.map((r) => (
          <li key={r} className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-900 ring-1 ring-inset ring-amber-600/20">
            <AlertTriangle className="size-3.5" aria-hidden />
            {attentionLabel[r]}: {data.counts[r]}
          </li>
        ))}
      </ul>
      <ul className="divide-y divide-slate-100">
        {data.items.map((item) => (
          <li key={item.asset.id}>
            <Link to={`/aset/${item.asset.id}`} className="flex items-start justify-between gap-2 px-4 py-2.5 hover:bg-slate-50">
              <div className="min-w-0 text-sm">
                <p className="truncate font-medium text-slate-900">{item.asset.name}</p>
                <p className="text-xs text-slate-500">
                  {attentionLabel[item.reason]}
                  {item.reason === 'overdue_loan' && item.borrower_name && ` · ${item.borrower_name}, batas ${formatDate(item.loan_due_date)}`}
                  {item.reason === 'poor_condition' && item.condition && ` · ${item.condition}`}
                </p>
              </div>
              <span className="flex shrink-0 items-center gap-1">
                <Badge tone={assetStatusTone[item.status]}>{assetStatusLabel[item.status]}</Badge>
                <ArrowRight className="size-4 text-slate-400" aria-hidden />
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

function StatTile({ icon: Icon, label, value, note, to }: { icon: typeof Boxes; label: string; value: string; note?: string; to?: string }) {
  const body = (
    <div className="flex h-full items-start gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <Icon className="mt-0.5 size-5 text-slate-400" aria-hidden />
      <div className="min-w-0">
        <p className="text-sm text-slate-600">{label}</p>
        <p className="mt-1 truncate text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
        {note && <p className="mt-0.5 text-xs text-slate-500">{note}</p>}
      </div>
    </div>
  )
  return to ? (
    <Link to={to} className="block rounded-lg hover:ring-2 hover:ring-brand-200">
      {body}
    </Link>
  ) : (
    body
  )
}

function Widget({ title, className, action, children }: { title: string; className?: string; action?: ReactNode; children: ReactNode }) {
  return (
    <Card title={title} className={className} actions={action}>
      <ModuleBoundary name={title} compact>
        {children}
      </ModuleBoundary>
    </Card>
  )
}
