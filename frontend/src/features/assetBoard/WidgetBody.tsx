import { ArrowRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { useAuth } from '../../lib/auth/context'
import { auditActionLabel, formatDate } from '../../lib/format'
import { assetStatusTone, type AssetStatus } from '../assets/api'
import { BarList, DonutChart, type SliceDatum } from '../../components/charts/charts'
import { monthShortLabel } from '../../components/charts/chartFormat'
import { CATEGORICAL, NEUTRAL } from '../../components/charts/palette'
import type { ActivityItem, WidgetPayload } from './api'
import { SeriesChart } from './SeriesChart'

const count = new Intl.NumberFormat('id-ID')
const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 })

function formatMoneyShort(value: number, currency = 'IDR'): string {
  const prefix = currency === 'IDR' ? 'Rp ' : `${currency} `
  return Math.abs(value) >= 1_000_000 ? `${prefix}${compact.format(value)}` : `${prefix}${count.format(Math.round(value))}`
}

const isMonth = (c: string) => /^\d{4}-\d{2}$/.test(c)
const isDate = (v: unknown): v is string => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v)

/**
 * Body of one widget, chosen by the shape of its data (WidgetPayload). Each kind has its own
 * empty message so a widget never shows a blank box.
 */
export function WidgetBody({ payload, currency }: { payload: WidgetPayload; currency?: string }) {
  if ('value' in payload) return <Statistic payload={payload} />
  if ('chart' in payload) return <ChartView payload={payload} currency={currency} />
  if ('columns' in payload) return <TableView columns={payload.columns} rows={payload.rows} total={payload.count} />
  if ('text' in payload) return payload.text.trim() ? <p className="whitespace-pre-wrap text-sm text-slate-700">{payload.text}</p> : <Empty>Catatan masih kosong.</Empty>
  if ('days' in payload) return <ReminderList payload={payload} />
  if ('count' in payload) return <ActivityList items={payload.items} />
  const items = payload.items as unknown[]
  if (items.length > 0 && typeof items[0] === 'object' && items[0] !== null && 'target' in items[0]) return <Shortcuts items={payload.items as ShortcutItem[]} />
  if (items.length > 0 && typeof items[0] === 'object' && items[0] !== null && 'status' in items[0]) {
    const rows = payload.items as { status: string; label: string; count: number }[]
    return <Bars data={rows.map((r) => ({ key: r.status, label: r.label, value: r.count, to: `/aset?status=${r.status}` }))} />
  }
  const rows = payload.items as { id: string | null; name: string; count: number }[]
  return <Bars data={rows.map((r) => ({ key: r.id ?? 'none', label: r.name, value: r.count, to: r.id ? `/aset?location_id=${r.id}` : undefined }))} />
}

function Statistic({ payload }: { payload: Extract<WidgetPayload, { value: unknown }> }) {
  const value = payload.value === null ? null : Number(payload.value)
  const text =
    value === null ? '—' : payload.unit === 'money' ? formatMoneyShort(value, payload.currency) : payload.unit === 'percent' ? `${value.toLocaleString('id-ID')}%` : count.format(value)
  return (
    <p className="text-3xl font-semibold tabular-nums text-slate-900" title={payload.unit === 'money' && value !== null ? `${payload.currency ?? ''} ${count.format(value)}` : undefined}>
      {text}
    </p>
  )
}

function ChartView({ payload, currency }: { payload: Extract<WidgetPayload, { chart: unknown }>; currency?: string }) {
  const { categories, series } = payload
  if (categories.length === 0 || series.every((s) => s.data.every((v) => !v))) return <Empty>Belum ada data untuk filter ini.</Empty>
  // Series with different units (count and value) are not drawn on one axis: the first leads.
  const units = new Set(series.map((s) => (s.key === 'value' ? 'money' : 'count')))
  const shown = units.size > 1 ? [series[0]] : series
  const money = shown[0].key === 'value'
  const format = (v: number) => (money ? formatMoneyShort(v, currency) : count.format(v))
  const label = (c: string) => (isMonth(c) ? monthShortLabel(c) : c)

  if (shown.length === 1 && payload.chart === 'donut') {
    const slices: SliceDatum[] = categories.map((c, i) => ({ key: `${i}`, label: label(c), value: shown[0].data[i] ?? 0, color: i < CATEGORICAL.length ? CATEGORICAL[i] : NEUTRAL }))
    return <DonutChart data={slices} totalLabel={shown[0].label} layout="row" />
  }
  if (shown.length === 1 && (payload.chart === 'bar' || payload.chart === 'list') && !categories.every(isMonth)) {
    const rows = categories.map((c, i) => ({ key: `${i}`, label: label(c), value: shown[0].data[i] ?? 0 }))
    if (payload.chart === 'list') {
      return (
        <ul className="divide-y divide-slate-100 text-sm">
          {rows.map((r) => (
            <li key={r.key} className="flex justify-between gap-3 py-1.5">
              <span className="text-slate-700">{r.label}</span>
              <span className="font-semibold tabular-nums text-slate-900">{format(r.value)}</span>
            </li>
          ))}
        </ul>
      )
    }
    return <BarList data={rows} unit="" format={format} />
  }
  return <SeriesChart categories={categories} series={shown} kind={payload.chart === 'line' ? 'line' : 'bar'} format={format} categoryLabel={label} />
}

function Bars({ data }: { data: { key: string; label: string; value: number; to?: string }[] }) {
  if (data.every((d) => d.value === 0)) return <Empty>Belum ada aset untuk filter ini.</Empty>
  return <BarList data={data} />
}

function TableView({ columns, rows, total }: { columns: { key: string; label: string }[]; rows: Record<string, unknown>[]; total: number }) {
  if (rows.length === 0) return <Empty>Tidak ada data.</Empty>
  return (
    <div className="-mx-4 overflow-x-auto">
      <table className="min-w-full text-left text-sm">
        <thead>
          <tr className="border-b border-slate-100 text-xs text-slate-500">
            {columns.map((c) => (
              <th key={c.key} scope="col" className="whitespace-nowrap px-4 py-2 font-semibold">
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row, i) => (
            <tr key={i} className={row.overdue === true ? 'bg-red-50/50' : undefined}>
              {columns.map((c) => (
                <td key={c.key} className="px-4 py-2 align-top text-slate-800">
                  <Cell column={c.key} value={row[c.key]} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {total > rows.length && <p className="px-4 pt-2 text-xs text-slate-500">Menampilkan {rows.length} dari {count.format(total)}</p>}
    </div>
  )
}

function Cell({ column, value }: { column: string; value: unknown }): ReactNode {
  if (value === null || value === undefined || value === '') return <span className="text-slate-400">—</span>
  if (column === 'days_late' && typeof value === 'number') return value > 0 ? <Badge tone="red">{value} hari</Badge> : <span>—</span>
  if (Array.isArray(value)) return value.join(', ')
  if (typeof value === 'object') {
    const v = value as { id?: string; name?: string | null; asset_number?: string; value?: string; label?: string }
    if (v.label !== undefined && v.value !== undefined) return <Badge tone={assetStatusTone[v.value as AssetStatus] ?? 'slate'}>{v.label}</Badge>
    if (v.id !== undefined) {
      return (
        <Link to={`/aset/${v.id}`} className="font-medium text-brand-700 hover:underline">
          {v.name ?? '—'}
          {v.asset_number && <span className="block font-mono text-xs font-normal text-slate-500">{v.asset_number}</span>}
        </Link>
      )
    }
    return JSON.stringify(value)
  }
  if (typeof value === 'number') return <span className="tabular-nums">{value.toLocaleString('id-ID')}</span>
  if (isDate(value)) return <span className="whitespace-nowrap">{formatDate(value)}</span>
  if (column === 'transaction') return <span className="font-mono text-xs font-semibold">{String(value)}</span>
  return String(value)
}

function ReminderList({ payload }: { payload: Extract<WidgetPayload, { days: number }> }) {
  if (payload.items.length === 0) return <Empty>Tidak ada pengingat {payload.days} hari ke depan.</Empty>
  return (
    <ul className="divide-y divide-slate-100 text-sm">
      {payload.items.map((r) => (
        <li key={`${r.asset.id}-${r.label}`}>
          <Link to={`/aset/${r.asset.id}`} className="-mx-2 flex items-start justify-between gap-3 rounded-md px-2 py-2 hover:bg-slate-50">
            <span className="min-w-0 text-slate-800">
              {r.asset.name}
              <span className="block text-xs text-slate-500">{r.label}</span>
            </span>
            <span className="whitespace-nowrap text-xs text-slate-600">{formatDate(r.due_date)}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

function ActivityList({ items }: { items: ActivityItem[] }) {
  if (items.length === 0) return <Empty>Belum ada aktivitas.</Empty>
  return (
    <ul className="divide-y divide-slate-100 text-sm">
      {items.map((i) => (
        <li key={i.id} className="flex items-start justify-between gap-3 py-2">
          <span className="min-w-0 text-slate-800">
            {i.transaction_number ? (
              <>
                <span className="font-mono text-xs font-semibold">{i.transaction_number}</span> · {i.type}
              </>
            ) : (
              auditActionLabel(i.action ?? '')
            )}
            <span className="block text-xs text-slate-500">
              {[i.asset?.name, i.created_by ?? i.actor].filter(Boolean).join(' · ')}
            </span>
          </span>
          <span className="whitespace-nowrap text-xs text-slate-500">{formatDate(i.transaction_date ?? i.at ?? null)}</span>
        </li>
      ))}
    </ul>
  )
}

interface ShortcutItem {
  key: string
  label: string
  target: string
  permission: string | null
}

function Shortcuts({ items }: { items: ShortcutItem[] }) {
  const { can } = useAuth()
  const usable = items.filter((i) => !i.permission || can(i.permission))
  if (usable.length === 0) return <Empty>Tidak ada pintasan yang dapat Anda pakai.</Empty>
  return (
    <ul className="grid gap-2 sm:grid-cols-2">
      {usable.map((i) => (
        <li key={i.key}>
          <Link to={i.target} className="flex items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-800 ring-1 ring-slate-200 hover:bg-brand-50 hover:ring-brand-200">
            {i.label}
            <ArrowRight className="size-4 text-brand-700" aria-hidden />
          </Link>
        </li>
      ))}
    </ul>
  )
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="py-3 text-sm text-slate-500">{children}</p>
}
