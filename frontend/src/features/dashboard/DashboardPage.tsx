import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowUpRight, Boxes, CalendarPlus, History, Wallet } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Card, PageHeader } from '../../components/ui/Card'
import { ErrorState, Skeleton } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { formatDate, formatDateTime } from '../../lib/format'
import { formatMoney, type AssetStatus } from '../assets/api'
import { transactionTypeLabel, type TransactionType } from '../transactions/api'
import { BarList, ColumnChart, DonutChart, Gauge, MeterList, StackedBar, TrendChart, type TrendPoint } from './charts'
import { CATEGORICAL, STATUS_COLOR } from './palette'

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
  attention: { counts: Record<AttentionReason, number>; items: { reason: AttentionReason; asset: AssetRef; status: AssetStatus; location: string | null; borrower_name: string | null; loan_due_date: string | null }[] }
  utilization: { in_service: number; available: number; operable: number; rate: number | null }
  loans: { active: number; overdue: number; due_soon: number; no_due_date: number; due_soon_days: number }
  age: { buckets: { key: string; label: string; count: number }[]; unknown: number; average_years: number | null }
  value_by_category: { id: string; name: string; value: string; count: number }[]
  data_quality: { total: number; fields: { key: string; label: string; filled: number }[]; score: number | null }
  idle: { days: number; count: number; items: { asset: AssetRef; location: string | null; last_activity: string; idle_days: number }[] }
}

type AssetRef = { id: string; asset_number: string; name: string }
type AttentionReason = 'overdue_loan' | 'under_maintenance' | 'no_location'

const attentionLabel: Record<AttentionReason, string> = {
  overdue_loan: 'Pinjaman lewat jatuh tempo',
  under_maintenance: 'Dalam perbaikan',
  no_location: 'Tanpa lokasi',
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

  const statusData = data.by_status.map((s, i) => ({ key: s.status, label: s.label, value: s.count, color: CATEGORICAL[i % CATEGORICAL.length], to: `/aset?status=${s.status}` }))
  const onTime = data.loans.active - data.loans.overdue - data.loans.due_soon
  const loanData = [
    { key: 'overdue', label: 'Terlambat', value: data.loans.overdue, color: STATUS_COLOR.critical },
    { key: 'due_soon', label: `Jatuh tempo ≤ ${data.loans.due_soon_days} hari`, value: data.loans.due_soon, color: STATUS_COLOR.warning },
    { key: 'on_time', label: 'Belum jatuh tempo', value: onTime, color: STATUS_COLOR.good },
  ]

  return (
    <div className="space-y-4">
      <section aria-label="Ringkasan" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile icon={Boxes} tone="brand" label="Aset aktif" value={data.summary.total.toLocaleString('id-ID')} note={data.summary.archived ? `${data.summary.archived} aset nonaktif tidak dihitung` : undefined} to="/aset" />
        <StatTile icon={Wallet} tone="emerald" label="Total nilai perolehan" value={formatMoney(data.summary.total_value)} note="Aset aktif yang nilainya diisi" />
        <StatTile icon={CalendarPlus} tone="violet" label="Ditambahkan bulan ini" value={data.summary.added_this_month.toLocaleString('id-ID')} />
        <StatTile icon={History} tone="amber" label={`Transaksi ${data.transactions.days} hari terakhir`} value={data.transactions.total.toLocaleString('id-ID')} to="/transaksi" />
      </section>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Widget title="Status aset">
          <DonutChart data={statusData} totalLabel="aset aktif" />
        </Widget>
        <Widget title="Pemanfaatan & peminjaman">
          <div className="space-y-4 p-4">
            <Gauge value={data.utilization.rate} label="Tingkat pemanfaatan" note={`${data.utilization.in_service} dari ${data.utilization.operable} aset digunakan/dipinjam`} />
            <div>
              <p className="mb-2 flex items-baseline justify-between text-sm">
                <span className="font-medium text-slate-700">Peminjaman aktif</span>
                <Link to="/aset?status=on_loan" className="font-semibold tabular-nums text-slate-900 hover:text-brand-700">
                  {data.loans.active.toLocaleString('id-ID')}
                </Link>
              </p>
              <StackedBar data={loanData} emptyText="Tidak ada aset yang sedang dipinjam." />
            </div>
          </div>
        </Widget>
        <Widget title="Kelengkapan data" className="md:col-span-2 xl:col-span-1">
          <div className="flex flex-wrap items-center gap-2 p-4 pb-0 xl:flex-col xl:items-stretch">
            <Gauge value={data.data_quality.score} label="Skor kelengkapan" note="Rata-rata kolom opsional terisi" />
            <div className="min-w-[200px] flex-1 [&>ul]:space-y-2 [&>ul]:px-0">
              <MeterList data={data.data_quality.fields.map((f) => ({ key: f.key, label: f.label, part: f.filled, total: data.data_quality.total }))} />
            </div>
          </div>
        </Widget>
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Widget title="Penambahan & perubahan aset (12 bulan)" className="xl:col-span-2">
          <TrendChart data={data.trend} />
        </Widget>
        <Widget
          title="Umur aset"
          action={data.age.average_years !== null && <span className="text-xs text-slate-500">Rata-rata <span className="font-semibold text-slate-900">{data.age.average_years.toLocaleString('id-ID')} tahun</span></span>}
        >
          {data.age.buckets.every((b) => b.count === 0) ? (
            <p className="p-4 text-sm text-slate-500">Belum ada aset dengan tanggal perolehan.</p>
          ) : (
            <div className="p-4">
              <ColumnChart label="Jumlah aset per kelompok umur" data={data.age.buckets.map((b) => ({ key: b.key, label: b.label, value: b.count }))} />
            </div>
          )}
          {data.age.unknown > 0 && <p className="px-4 pb-4 text-xs text-slate-500">{data.age.unknown} aset tanpa tanggal perolehan tidak dihitung.</p>}
        </Widget>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <CategoryWidget data={data} />
        <Widget title="Per lokasi">
          <BarList limit={6} data={data.by_location.map((l) => ({ key: l.id ?? 'none', label: l.name, value: l.count, to: l.id ? `/aset?location_id=${l.id}` : undefined }))} />
        </Widget>
        <Widget title={`Transaksi per jenis (${data.transactions.days} hari)`} className="md:col-span-2 xl:col-span-1">
          <BarList limit={6} unit="transaksi" emptyText="Belum ada transaksi dalam periode ini." data={data.transactions.by_type.filter((t) => t.count > 0).sort((a, b) => b.count - a.count).map((t) => ({ key: t.type, label: t.label, value: t.count, to: `/transaksi?type=${t.type}` }))} />
        </Widget>
      </div>

      <ActivityWidget data={data} />
    </div>
  )
}

/** Count and acquisition value per category share one card; a toggle switches the measure. */
function CategoryWidget({ data }: { data: Dashboard }) {
  const [measure, setMeasure] = useState<'count' | 'value'>('count')
  const toggle = (
    <div className="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium" role="group" aria-label="Ukuran">
      {(['count', 'value'] as const).map((m) => (
        <button key={m} type="button" aria-pressed={measure === m} onClick={() => setMeasure(m)} className={clsx('rounded-md px-2.5 py-1 transition-colors', measure === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900')}>
          {m === 'count' ? 'Jumlah' : 'Nilai'}
        </button>
      ))}
    </div>
  )
  return (
    <Widget title="Per kategori" action={toggle}>
      {measure === 'count' ? (
        <BarList limit={6} data={data.by_category.map((c) => ({ key: c.id, label: c.name, value: c.count, to: `/aset?category_ids=${c.id}` }))} />
      ) : (
        <BarList limit={6} unit="" format={formatMoney} emptyText="Belum ada aset dengan nilai perolehan." data={data.value_by_category.map((c) => ({ key: c.id, label: c.name, value: Number(c.value), to: `/aset?category_ids=${c.id}` }))} />
      )}
    </Widget>
  )
}

type ActivityTab = 'attention' | 'idle' | 'recent'

/** The three asset lists share one tabbed card with a fixed height, so the page stays short. */
function ActivityWidget({ data }: { data: Dashboard }) {
  const attentionTotal = Object.values(data.attention.counts).reduce((s, n) => s + n, 0)
  const [tab, setTab] = useState<ActivityTab>(attentionTotal > 0 ? 'attention' : 'recent')
  const tabs: { key: ActivityTab; label: string; count?: number }[] = [
    { key: 'attention', label: 'Perlu tindak lanjut', count: attentionTotal },
    { key: 'idle', label: `Aset menganggur (≥ ${data.idle.days} hari)`, count: data.idle.count },
    { key: 'recent', label: 'Transaksi terbaru' },
  ]

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3 pt-2">
        <div role="tablist" aria-label="Daftar aset" className="flex flex-wrap gap-1">
          {tabs.map((t) => (
            <button
              key={t.key}
              type="button"
              role="tab"
              id={`tab-${t.key}`}
              aria-selected={tab === t.key}
              aria-controls={`panel-${t.key}`}
              onClick={() => setTab(t.key)}
              className={clsx('-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors', tab === t.key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:text-slate-900')}
            >
              {t.label}
              {t.count !== undefined && <span className={clsx('rounded-full px-1.5 text-xs tabular-nums', t.count > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600')}>{t.count}</span>}
            </button>
          ))}
        </div>
        {tab === 'recent' && (
          <Link to="/transaksi" className="px-2 text-sm font-medium text-brand-700 hover:underline">
            Lihat semua
          </Link>
        )}
      </div>
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="max-h-80 overflow-y-auto">
        <ModuleBoundary name={tabs.find((t) => t.key === tab)!.label} compact>
          {tab === 'attention' ? <AttentionList attention={data.attention} /> : tab === 'idle' ? <IdleList idle={data.idle} /> : <RecentList recent={data.transactions.recent} />}
        </ModuleBoundary>
      </div>
    </Card>
  )
}

function RecentList({ recent }: { recent: Dashboard['transactions']['recent'] }) {
  if (recent.length === 0) return <p className="p-4 text-sm text-slate-500">Belum ada transaksi.</p>
  return (
    <ul className="divide-y divide-slate-100">
      {recent.map((t) => (
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
  )
}

function AttentionList({ attention }: { attention: Dashboard['attention'] }) {
  const reasons = Object.keys(attentionLabel) as AttentionReason[]
  return (
    <>
      <ul className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3 text-xs">
        {reasons.map((r) => (
          <li key={r} className="rounded-md bg-slate-100 px-2 py-1 text-slate-700">
            {attentionLabel[r]}: <span className="font-semibold tabular-nums text-slate-900">{attention.counts[r]}</span>
          </li>
        ))}
      </ul>
      {attention.items.length === 0 ? (
        <p className="p-4 text-sm text-slate-500">Tidak ada aset yang perlu ditindaklanjuti.</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {attention.items.map((item) => (
            <li key={item.asset.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm transition-colors hover:bg-slate-50">
              <div className="min-w-0">
                <Link to={`/aset/${item.asset.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                  {item.asset.name}
                </Link>
                <p className="text-xs text-slate-500">
                  {item.asset.asset_number}
                  {item.reason === 'overdue_loan' && item.borrower_name && ` · ${item.borrower_name}`}
                  {item.reason === 'overdue_loan' && item.loan_due_date && ` · jatuh tempo ${formatDate(item.loan_due_date)}`}
                  {item.reason !== 'overdue_loan' && item.location && ` · ${item.location}`}
                </p>
              </div>
              <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{attentionLabel[item.reason]}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function IdleList({ idle }: { idle: Dashboard['idle'] }) {
  if (idle.count === 0) return <p className="p-4 text-sm text-slate-500">Tidak ada aset tersedia yang menganggur lebih dari {idle.days} hari.</p>
  return (
    <>
      <p className="border-b border-slate-100 px-5 py-3 text-sm text-slate-600">
        <span className="font-semibold tabular-nums text-slate-900">{idle.count.toLocaleString('id-ID')}</span> aset berstatus Tersedia tanpa transaksi apa pun. Pertimbangkan untuk dialokasikan ulang atau dihapuskan.
      </p>
      <ul className="divide-y divide-slate-100">
        {idle.items.map((item) => (
          <li key={item.asset.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm transition-colors hover:bg-slate-50">
            <div className="min-w-0">
              <Link to={`/aset/${item.asset.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                {item.asset.name}
              </Link>
              <p className="text-xs text-slate-500">
                {item.asset.asset_number}
                {item.location && ` · ${item.location}`} · aktivitas terakhir {formatDate(item.last_activity)}
              </p>
            </div>
            <span className="shrink-0 text-xs font-medium tabular-nums text-slate-600">{item.idle_days.toLocaleString('id-ID')} hari</span>
          </li>
        ))}
      </ul>
    </>
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
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="flex flex-col items-center gap-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80">
            <Skeleton className="h-4 w-36 self-start" />
            <Skeleton className="size-36 rounded-full" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-full" />
          </div>
        ))}
      </div>
      <div className="grid gap-4 xl:grid-cols-3">
        <div className="rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80 xl:col-span-2">
          <Skeleton className="mb-5 h-4 w-56" />
          <Skeleton className="h-52 w-full" />
        </div>
        <div className="rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80">
          <Skeleton className="mb-5 h-4 w-28" />
          <Skeleton className="h-52 w-full" />
        </div>
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
