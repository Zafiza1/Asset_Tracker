import clsx from 'clsx'
import { BarChart3, ChevronDown } from 'lucide-react'
import { useState } from 'react'
import { Card } from '../../components/ui/Card'
import { formatMoney } from '../assets/api'
import { SegmentedControl } from '../../components/ui/Tabs'

/** One bar of a breakdown; `onSelect` makes the row a filter shortcut. */
export interface BarRow {
  key: string
  label: string
  count: number
  value?: number
  active?: boolean
  onSelect?: () => void
}

type Metric = 'count' | 'value'

/** Rows past this fold into one "Lainnya" bar, so a long tail never pushes the panel down. */
const TOP = 6
const STORAGE_KEY = 'report-assets:charts-open'

const readOpen = () => {
  try {
    return localStorage.getItem(STORAGE_KEY) !== '0'
  } catch {
    return true
  }
}

const count = (n: number) => n.toLocaleString('id-ID')
const percent = (part: number, whole: number) => (whole > 0 ? `${((part / whole) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : '0%')

/**
 * "Ringkasan grafik": the report's selection broken down by status, category and location as
 * horizontal bars. Every chart is one series in one hue, so it needs no legend; label, amount and
 * share are printed as text beside each bar. Clicking a bar narrows the report to that group.
 */
export function ReportCharts({ panels }: { panels: { title: string; description: string; rows: BarRow[]; withValue?: boolean }[] }) {
  const [open, setOpen] = useState(readOpen)
  const toggle = () =>
    setOpen((o) => {
      try {
        localStorage.setItem(STORAGE_KEY, o ? '0' : '1')
      } catch {
        // Storage blocked: the panel just won't remember its state.
      }
      return !o
    })

  return (
    <Card className="mb-4">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-5 py-3.5 text-left text-base font-semibold text-slate-900 hover:text-brand-700"
      >
        <BarChart3 className="size-5 text-slate-400" aria-hidden />
        Ringkasan grafik
        <span className="ml-1 hidden text-xs font-normal text-slate-500 sm:inline">Klik batang untuk menyaring laporan.</span>
        <ChevronDown className={clsx('ml-auto size-4 text-slate-400 transition-transform', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div className="grid gap-x-6 gap-y-6 border-t border-slate-100 p-5 lg:grid-cols-3">
          {panels.map((p) => (
            <BarPanel key={p.title} {...p} />
          ))}
        </div>
      )}
    </Card>
  )
}

function BarPanel({ title, description, rows, withValue = false }: { title: string; description: string; rows: BarRow[]; withValue?: boolean }) {
  const [metric, setMetric] = useState<Metric>('count')
  const amount = (r: BarRow) => (metric === 'value' ? (r.value ?? 0) : r.count)
  const sorted = metric === 'count' ? rows : [...rows].sort((a, b) => amount(b) - amount(a))
  const shown = sorted.length > TOP + 1 ? sorted.slice(0, TOP) : sorted
  const rest = sorted.slice(shown.length)
  const bars: BarRow[] = rest.length
    ? [...shown, { key: '__rest', label: `Lainnya (${rest.length})`, count: rest.reduce((s, r) => s + r.count, 0), value: rest.reduce((s, r) => s + (r.value ?? 0), 0) }]
    : shown
  const whole = rows.reduce((s, r) => s + amount(r), 0)
  const max = Math.max(...bars.map(amount), 0)
  const format = (n: number) => (metric === 'value' ? formatMoney(n) : `${count(n)} aset`)

  return (
    <section aria-label={title} className="min-w-0">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
          <p className="text-xs text-slate-500">{description}</p>
        </div>
        {withValue && (
          <SegmentedControl
            size="sm"
            label={`Ukuran ${title.toLowerCase()}`}
            value={metric}
            onChange={setMetric}
            options={[
              { value: 'count', label: 'Jumlah' },
              { value: 'value', label: 'Nilai' },
            ]}
          />
        )}
      </div>
      {bars.length === 0 ? (
        <p className="py-4 text-center text-xs text-slate-400">Tidak ada data.</p>
      ) : (
        <ul className="space-y-1">
          {bars.map((r) => {
            const n = amount(r)
            const share = percent(n, whole)
            const tip = `${r.label}: ${format(n)} (${share})${metric === 'count' && withValue ? ` · ${formatMoney(r.value ?? 0)}` : ''}`
            const body = (
              <>
                <span className="flex items-baseline justify-between gap-2 text-xs">
                  <span className={clsx('truncate', r.active ? 'font-semibold text-brand-800' : 'text-slate-700')}>{r.label}</span>
                  <span className="shrink-0 tabular-nums text-slate-500">
                    <span className="font-medium text-slate-800">{metric === 'value' ? formatMoney(n) : count(n)}</span> · {share}
                  </span>
                </span>
                <span className="mt-1 block h-2 rounded-full bg-slate-100">
                  <span
                    className={clsx('block h-2 rounded-full', r.key === '__rest' ? 'bg-slate-400' : r.active ? 'bg-brand-800' : 'bg-brand-600')}
                    style={{ width: max > 0 ? `${Math.max((n / max) * 100, n > 0 ? 1.5 : 0)}%` : 0 }}
                  />
                </span>
              </>
            )
            return (
              <li key={r.key}>
                {r.onSelect ? (
                  <button type="button" onClick={r.onSelect} title={`${tip} — klik untuk menyaring`} aria-pressed={r.active} className="block w-full rounded-md px-2 py-1.5 text-left hover:bg-slate-50 focus-visible:bg-slate-50">
                    {body}
                  </button>
                ) : (
                  <div title={tip} className="px-2 py-1.5">
                    {body}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
