import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowUpRight, Boxes, CalendarPlus, History, Wallet } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Card, PageHeader } from '../../components/ui/Card'
import { ErrorState, Skeleton } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { formatDate, formatDateTime } from '../../lib/format'
import { formatMoney, type AssetStatus } from '../assets/api'
import { transactionTypeLabel, type TransactionType } from '../transactions/api'
import { BarList, TrendChart, type TrendPoint } from './charts'

export interface Dashboard {
  generated_at: string
  summary: { total: number; archived: number; total_value: string; added_this_month: number; currency: string }
  by_status: { status: AssetStatus; label: string; count: number }[]
  by_category: { id: string; name: string; count: number }[]
  by_location: { id: string | null; name: string; count: number }[]
  transactions: {
    days: number
    total: number
    by_type: { type: TransactionType; label: string; count: number }[]
    recent: { id: string; transaction_number: string; type: TransactionType; transaction_date: string; asset: { id: string; asset_number: string; name: string }; created_by: string | null }[]
  }
  trend: TrendPoint[]
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
      {query.isLoading ? <DashboardSkeleton /> : query.isError ? <ErrorState error={query.error} /> : <DashboardBody data={query.data!.data} />}
    </>
  )
}

function DashboardBody({ data }: { data: Dashboard }) {
  if (data.summary.total === 0 && data.summary.archived === 0) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
          <span className="mb-2 grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-700 ring-1 ring-brand-100">
            <Boxes className="size-7" aria-hidden />
          </span>
          <p className="font-display text-lg font-semibold text-slate-900">Belum ada aset terdaftar</p>
          <p className="max-w-md text-sm text-slate-500">Analisis muncul otomatis setelah aset ditambahkan. Mulai dari menu Pendataan Aset.</p>
          <Link to="/aset" className="mt-3 inline-flex min-h-10 items-center rounded-lg bg-brand-700 px-4 text-sm font-semibold text-white shadow-card transition-colors hover:bg-brand-800">
            Buka Pendataan Aset
          </Link>
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-4">
      <section aria-label="Ringkasan" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile icon={Boxes} tone="brand" label="Aset aktif" value={data.summary.total.toLocaleString('id-ID')} note={data.summary.archived ? `${data.summary.archived} aset nonaktif tidak dihitung` : undefined} to="/aset" />
        <StatTile icon={Wallet} tone="emerald" label="Total nilai perolehan" value={formatMoney(data.summary.total_value)} note="Aset aktif yang nilainya diisi" />
        <StatTile icon={CalendarPlus} tone="violet" label="Ditambahkan bulan ini" value={data.summary.added_this_month.toLocaleString('id-ID')} />
        <StatTile icon={History} tone="amber" label={`Transaksi ${data.transactions.days} hari terakhir`} value={data.transactions.total.toLocaleString('id-ID')} to="/transaksi" />
      </section>

      <Widget title="Penambahan & perubahan aset (12 bulan)">
        <TrendChart data={data.trend} />
      </Widget>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Widget title="Per kategori">
          <BarList data={data.by_category.map((c) => ({ key: c.id, label: c.name, value: c.count, to: `/aset?category_ids=${c.id}` }))} />
        </Widget>
        <Widget title="Per lokasi">
          <BarList data={data.by_location.map((l) => ({ key: l.id ?? 'none', label: l.name, value: l.count, to: l.id ? `/aset?location_id=${l.id}` : undefined }))} />
        </Widget>
        <Widget title="Per status">
          <BarList data={data.by_status.map((s) => ({ key: s.status, label: s.label, value: s.count, to: `/aset?status=${s.status}` }))} />
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
                <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm transition-colors hover:bg-slate-50">
                  <div className="min-w-0">
                    <Link to={`/aset/${t.asset.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                      {t.asset.name}
                    </Link>
                    <p className="text-xs text-slate-500">
                      {transactionTypeLabel[t.type]} · {formatDate(t.transaction_date)}
                      {t.created_by && ` · ${t.created_by}`}
                    </p>
                  </div>
                  <span className="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600">{t.transaction_number}</span>
                </li>
              ))}
            </ul>
          )}
        </Widget>
      </div>
    </div>
  )
}

/** Icon chip tints: decorative only — every tile is identified by its text label. */
const tileTones = {
  brand: 'bg-brand-50 text-brand-700 ring-brand-100',
  emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
  violet: 'bg-violet-50 text-violet-700 ring-violet-100',
  amber: 'bg-amber-50 text-amber-700 ring-amber-100',
} as const

function StatTile({ icon: Icon, tone, label, value, note, to }: { icon: typeof Boxes; tone: keyof typeof tileTones; label: string; value: string; note?: string; to?: string }) {
  const body = (
    <div className={clsx('flex h-full items-start gap-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80 transition-shadow duration-200', to && 'group-hover:shadow-raised group-hover:ring-brand-200')}>
      <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl ring-1', tileTones[tone])}>
        <Icon className="size-5" aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="flex items-center justify-between gap-2 text-sm font-medium text-slate-600">
          {label}
          {to && <ArrowUpRight className="size-4 shrink-0 text-slate-300 transition-colors group-hover:text-brand-600" aria-hidden />}
        </p>
        <p className="mt-1 truncate font-display text-2xl font-semibold tabular-nums tracking-tight text-slate-900" title={value}>
          {value}
        </p>
        {note && <p className="mt-0.5 text-xs text-slate-500">{note}</p>}
      </div>
    </div>
  )
  return to ? (
    <Link to={to} className="group block rounded-xl">
      {body}
    </Link>
  ) : (
    body
  )
}

/** Mirrors the real layout so nothing jumps when the data arrives. */
function DashboardSkeleton() {
  return (
    <div className="space-y-4" role="status" aria-label="Memuat dashboard">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="flex gap-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80">
            <Skeleton className="size-11 rounded-xl" />
            <div className="flex-1 space-y-2.5 py-0.5">
              <Skeleton className="h-3.5 w-2/3" />
              <Skeleton className="h-7 w-1/2" />
            </div>
          </div>
        ))}
      </div>
      <div className="rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80">
        <Skeleton className="mb-5 h-4 w-56" />
        <Skeleton className="h-52 w-full" />
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="space-y-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80">
            <Skeleton className="h-4 w-28" />
            {[0, 1, 2, 3].map((j) => (
              <div key={j} className="space-y-1.5">
                <Skeleton className="h-3 w-1/2" />
                <Skeleton className="h-2.5" />
              </div>
            ))}
          </div>
        ))}
      </div>
      <span className="sr-only">Memuat…</span>
    </div>
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
