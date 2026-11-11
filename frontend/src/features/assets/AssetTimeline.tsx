import clsx from 'clsx'
import {
  ArrowLeftRight,
  ArrowRight,
  FileText,
  PackagePlus,
  Pencil,
  Power,
  RefreshCw,
  RotateCcw,
  StickyNote,
  Undo2,
  UserRoundPlus,
  type LucideIcon,
} from 'lucide-react'
import { useState } from 'react'
import { EmptyState } from '../../components/ui/Feedback'
import { formatDate, formatDateTime } from '../../lib/format'
import type { AssetTransaction, TransactionType } from '../transactions/api'
import { TransactionChanges } from '../transactions/TransactionChanges'
import { formatMoney } from './api'

/** One entry of GET /assets/{id}/timeline (App\Domain\Assets\AssetTimeline). */
export type TimelineEvent =
  | { id: string; kind: 'transaction'; occurred_at: string; actor: string | null; transaction: AssetTransaction }
  | { id: string; kind: 'data_change' | 'file_change'; occurred_at: string; actor: string | null; title: string; changes: FieldChange[] }

export interface FieldChange {
  field: string
  label: string
  before: unknown
  after: unknown
}

type Filter = 'all' | 'transaction' | 'change'

const FILTERS: { key: Filter; label: string }[] = [
  { key: 'all', label: 'Semua' },
  { key: 'transaction', label: 'Transaksi' },
  { key: 'change', label: 'Perubahan data' },
]

const TYPE_STYLE: Record<TransactionType, { icon: LucideIcon; tone: string }> = {
  registration: { icon: PackagePlus, tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  transfer: { icon: ArrowLeftRight, tone: 'bg-brand-50 text-brand-700 ring-brand-200' },
  status_change: { icon: RefreshCw, tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
  loan: { icon: UserRoundPlus, tone: 'bg-violet-50 text-violet-700 ring-violet-200' },
  return: { icon: Undo2, tone: 'bg-violet-50 text-violet-700 ring-violet-200' },
  note: { icon: StickyNote, tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
  archive: { icon: Power, tone: 'bg-red-50 text-red-700 ring-red-200' },
  restore: { icon: RotateCcw, tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
}

const EDIT_STYLE = { icon: Pencil, tone: 'bg-slate-100 text-slate-600 ring-slate-200' }
const FILE_STYLE = { icon: FileText, tone: 'bg-slate-100 text-slate-600 ring-slate-200' }

const monthLabel = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })

/** The day an event belongs to: the stated date of a transaction, the timestamp of an edit. */
const eventDay = (e: TimelineEvent) => (e.kind === 'transaction' ? e.transaction.transaction_date : e.occurred_at)

/** A changed value as people read it: money, dates, yes/no, lists. */
export function formatChangeValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (field === 'acquisition_cost') return formatMoney(value as string | number)
  if (field === 'acquisition_date') return formatDate(String(value))
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

/** The life of one asset as a vertical timeline, newest first and grouped by month. */
export function AssetTimeline({ events }: { events: TimelineEvent[] }) {
  const [filter, setFilter] = useState<Filter>('all')
  const hasTransactions = events.some((e) => e.kind === 'transaction')
  const shown = events.filter((e) => filter === 'all' || (filter === 'transaction') === (e.kind === 'transaction'))

  const groups: { month: string; events: TimelineEvent[] }[] = []
  for (const e of shown) {
    const month = monthLabel.format(new Date(eventDay(e)))
    if (groups.at(-1)?.month !== month) groups.push({ month, events: [] })
    groups.at(-1)!.events.push(e)
  }

  return (
    <div>
      {hasTransactions && (
        <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-5 py-3" role="group" aria-label="Saring timeline">
          {FILTERS.map((f) => (
            <button
              key={f.key}
              type="button"
              aria-pressed={filter === f.key}
              onClick={() => setFilter(f.key)}
              className={clsx(
                'rounded-full px-3 py-1 text-xs font-medium ring-1 transition-colors',
                filter === f.key ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50',
              )}
            >
              {f.label}
            </button>
          ))}
        </div>
      )}

      {shown.length === 0 ? (
        <EmptyState title={events.length === 0 ? 'Belum ada riwayat' : 'Tidak ada kejadian untuk filter ini'} />
      ) : (
        <div className="space-y-5 px-5 py-4">
          {groups.map((g) => (
            <section key={g.month}>
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{g.month}</h3>
              <ol className="relative space-y-4 before:absolute before:bottom-2 before:left-[1.0625rem] before:top-2 before:w-px before:bg-slate-200">
                {g.events.map((e) => (
                  <TimelineItem key={e.id} event={e} />
                ))}
              </ol>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

function TimelineItem({ event: e }: { event: TimelineEvent }) {
  const style = e.kind === 'transaction' ? TYPE_STYLE[e.transaction.type] ?? EDIT_STYLE : e.kind === 'file_change' ? FILE_STYLE : EDIT_STYLE
  const Icon = style.icon

  return (
    <li className="relative flex gap-3">
      <span className={clsx('relative z-10 grid size-9 shrink-0 place-items-center rounded-full ring-1', style.tone)} aria-hidden>
        <Icon className="size-4" />
      </span>
      <div className="min-w-0 flex-1 pt-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
          <p className="text-sm font-semibold text-slate-900">
            {e.kind === 'transaction' ? e.transaction.type_label : e.title}
            {e.kind === 'transaction' && <span className="ml-2 font-mono text-xs font-normal text-slate-500">{e.transaction.transaction_number}</span>}
          </p>
          <time className="text-xs text-slate-500" dateTime={e.occurred_at} title={`Dicatat ${formatDateTime(e.occurred_at)}`}>
            {e.kind === 'transaction' ? formatDate(e.transaction.transaction_date) : formatDateTime(e.occurred_at)}
          </time>
        </div>
        {e.actor && <p className="text-xs text-slate-500">oleh {e.actor}</p>}
        <div className="mt-1.5">{e.kind === 'transaction' ? <TransactionChanges trx={e.transaction} /> : <FieldChanges changes={e.changes} />}</div>
      </div>
    </li>
  )
}

function FieldChanges({ changes }: { changes: FieldChange[] }) {
  if (changes.length === 0) return null
  return (
    <ul className="space-y-0.5 text-sm">
      {changes.map((c) => (
        <li key={c.field} className="flex flex-wrap items-center gap-1 text-slate-700 [overflow-wrap:anywhere]">
          <span className="text-slate-500">{c.label}:</span>
          <span className="text-slate-500 line-through decoration-slate-300">{formatChangeValue(c.field, c.before)}</span>
          <ArrowRight className="size-3.5 text-slate-400" aria-label="menjadi" />
          <span className="font-medium text-slate-900">{formatChangeValue(c.field, c.after)}</span>
        </li>
      ))}
    </ul>
  )
}
