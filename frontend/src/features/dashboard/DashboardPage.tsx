import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Boxes, Check, LayoutGrid, Plus, RotateCcw } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { Button } from '../../components/ui/Button'
import { ErrorState, Skeleton } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { formatDateTime } from '../../lib/format'
import type { AssetStatus } from '../assets/api'
import type { TransactionType } from '../transactions/api'
import type { TrendPoint } from './charts'
import { DASHBOARD_WIDGETS, SIZE_CLASS, useDashboardLayout, widgetDef, type LayoutItem, type WidgetKey } from './layout'
import { GRID_CLASS, WidgetGrid } from './WidgetGrid'
import { DashboardWidget } from './widgets'

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
  age: {
    buckets: { key: string; label: string; count: number }[]
    unknown: number
    average_years: number | null
    by_category: { id: string; name: string; total: number; average_years: number; buckets: Record<string, number> }[]
    oldest: { asset: AssetRef; location: string | null; acquisition_date: string; age_years: number }[]
  }
  value_by_category: { id: string; name: string; value: string; count: number }[]
  data_quality: { total: number; fields: { key: string; label: string; filled: number }[]; score: number | null }
  idle: { days: number; count: number; items: { asset: AssetRef; location: string | null; last_activity: string; idle_days: number }[] }
}

type AssetRef = { id: string; asset_number: string; name: string }
type AttentionReason = 'overdue_loan' | 'under_maintenance' | 'no_location'

/**
 * Asset Analytics module: everything here is computed by the API from the stored data,
 * within the user's data scope. Each widget has its own error boundary, draws a different
 * view at each width, and the user arranges them (order, width, visibility) with "Atur tata letak".
 */
export function DashboardPage() {
  const { profile } = useAuth()
  const query = useQuery({ queryKey: ['/dashboard'], queryFn: () => api.get<{ data: Dashboard }>('/dashboard') })
  const layout = useDashboardLayout(profile?.user.id)
  const [editing, setEditing] = useState(false)
  const hasAssets = query.data ? query.data.data.summary.total > 0 || query.data.data.summary.archived > 0 : false

  return (
    <>
      <PageHeader
        title="Dashboard Aset"
        description={profile?.organization?.name}
        actions={
          <>
            {query.data && <span className="text-xs text-slate-500">Data per {formatDateTime(query.data.data.generated_at)}</span>}
            <ColumnToggle
              label="Tampilan"
              title="Tampilkan analisis"
              columns={[...DASHBOARD_WIDGETS]}
              visibleColumns={layout.items.filter((i) => !i.hidden).map((i) => i.key)}
              onToggle={(key) => layout.setHidden(key as WidgetKey, !layout.items.find((i) => i.key === key)!.hidden)}
              onReset={layout.showAll}
            />
            {hasAssets &&
              (editing ? (
                <Button size="sm" onClick={() => setEditing(false)}>
                  <Check className="size-4" aria-hidden />
                  Selesai
                </Button>
              ) : (
                <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
                  <LayoutGrid className="size-4" aria-hidden />
                  Atur tata letak
                </Button>
              ))}
          </>
        }
      />
      {query.isLoading ? (
        <DashboardSkeleton items={layout.items} />
      ) : query.isError ? (
        <ErrorState error={query.error} />
      ) : !hasAssets ? (
        <NoAssets />
      ) : (
        <div className="space-y-4">
          {editing && <LayoutToolbar layout={layout} />}
          {layout.items.every((i) => i.hidden) ? (
            <AllHidden onShowAll={layout.showAll} />
          ) : (
            <WidgetGrid
              items={layout.items.filter((i) => !i.hidden)}
              editing={editing}
              render={(key) => <DashboardWidget widgetKey={key} data={query.data!.data} />}
              onMove={layout.move}
              onResize={layout.resize}
              onHide={(key) => layout.setHidden(key, true)}
            />
          )}
        </div>
      )}
    </>
  )
}

/** Shown while arranging: how it works, the hidden widgets to bring back, and a reset. */
function LayoutToolbar({ layout }: { layout: ReturnType<typeof useDashboardLayout> }) {
  const hidden = layout.items.filter((i) => i.hidden)
  return (
    <section aria-label="Atur tata letak" className="rounded-xl bg-brand-50/60 p-4 ring-1 ring-brand-100">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-2xl text-sm text-slate-700">Seret widget untuk mengubah urutan, pilih lebarnya dalam rasio grid (mis. 1:2 = setengah baris, 1:1 = penuh), atau sembunyikan yang tidak diperlukan. Isi widget menyesuaikan lebarnya: makin lebar, makin lengkap analisisnya. Perubahan tersimpan otomatis di browser ini.</p>
        <Button variant="ghost" size="sm" onClick={layout.reset}>
          <RotateCcw className="size-4" aria-hidden />
          Kembalikan bawaan
        </Button>
      </div>
      {hidden.length > 0 && (
        <div className="mt-3">
          <p className="mb-2 text-xs font-medium text-slate-600">Widget tersembunyi</p>
          <ul className="flex flex-wrap gap-2">
            {hidden.map((i) => (
              <li key={i.key}>
                <button
                  type="button"
                  onClick={() => layout.setHidden(i.key, false)}
                  className="inline-flex min-h-8 items-center gap-1.5 rounded-lg bg-white px-2.5 text-sm text-slate-700 shadow-card ring-1 ring-slate-300 transition-colors hover:text-brand-800 hover:ring-brand-300"
                >
                  <Plus className="size-4" aria-hidden />
                  {widgetDef(i.key).header}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

function NoAssets() {
  return (
    <Card>
      <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
        <span className="mb-2 grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-700 ring-1 ring-brand-100">
          <Boxes className="size-7" aria-hidden />
        </span>
        <p className="font-display text-lg font-semibold text-slate-900">Belum ada aset terdaftar</p>
        <p className="max-w-md text-sm text-slate-500">Analisis muncul otomatis setelah aset ditambahkan. Mulai dari menu Data Aset.</p>
        <Link to="/aset" className="mt-3 inline-flex min-h-10 items-center rounded-lg bg-brand-700 px-4 text-sm font-semibold text-white shadow-card transition-colors hover:bg-brand-800">
          Buka Data Aset
        </Link>
      </div>
    </Card>
  )
}

function AllHidden({ onShowAll }: { onShowAll: () => void }) {
  return (
    <Card>
      <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
        <p className="font-display text-lg font-semibold text-slate-900">Semua analisis disembunyikan</p>
        <p className="max-w-md text-sm text-slate-500">Tampilkan kembali lewat Atur tata letak, atau tampilkan semuanya sekaligus.</p>
        <button type="button" onClick={onShowAll} className="mt-3 inline-flex min-h-10 items-center rounded-lg bg-brand-700 px-4 text-sm font-semibold text-white shadow-card transition-colors hover:bg-brand-800">
          Tampilkan semua
        </button>
      </div>
    </Card>
  )
}

/** Placeholders in the user's own layout so nothing jumps when the data arrives. */
function DashboardSkeleton({ items }: { items: LayoutItem[] }) {
  return (
    <div className={GRID_CLASS} role="status" aria-label="Memuat dashboard">
      {items
        .filter((i) => !i.hidden)
        .map((i) =>
          widgetDef(i.key).kind === 'stat' ? (
            <div key={i.key} className={clsx('flex gap-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80', SIZE_CLASS[i.size])}>
              <Skeleton className="size-11 rounded-xl" />
              <div className="flex-1 space-y-2.5 py-0.5">
                <Skeleton className="h-3.5 w-2/3" />
                <Skeleton className="h-7 w-1/2" />
              </div>
            </div>
          ) : (
            <div key={i.key} className={clsx('rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80', SIZE_CLASS[i.size])}>
              <Skeleton className="mb-5 h-4 w-40" />
              <Skeleton className="h-52 w-full" />
            </div>
          ),
        )}
      <span className="sr-only">Memuat…</span>
    </div>
  )
}
