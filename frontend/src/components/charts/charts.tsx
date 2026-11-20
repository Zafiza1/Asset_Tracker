import clsx from 'clsx'
import { useState } from 'react'
import { Link } from 'react-router'
import { formatPercent } from '../../lib/format'
import { monthLongLabel, monthShortLabel } from './chartFormat'

/**
 * Small dependency-free charts for the dashboard.
 * Colors: categorical slots 1–2 of the validated reference palette (light surface);
 * magnitude bars use slot 1 only. Text never takes a series color.
 */
const SERIES = {
  one: '#2a78d6',
  two: '#eb6834',
} as const

const TRACK = '#e2e8f0'

export interface SliceDatum {
  key: string
  label: string
  value: number
  color: string
  to?: string
}

/**
 * Part-to-whole donut for a handful of classes. The legend prints every value and share,
 * so it doubles as the table; hovering a slice or legend row shows it in the center.
 */
export function DonutChart({ data, totalLabel, emptyText = 'Belum ada data.', layout = 'stacked' }: { data: SliceDatum[]; totalLabel: string; emptyText?: string; layout?: 'stacked' | 'row' }) {
  const [hover, setHover] = useState<string | null>(null)
  const total = data.reduce((sum, d) => sum + d.value, 0)
  if (total === 0) return <p className="p-4 text-sm text-slate-500">{emptyText}</p>

  const size = 168
  const stroke = 24
  const r = (size - stroke) / 2
  const circ = 2 * Math.PI * r
  const visible = data.filter((d) => d.value > 0)
  const gap = visible.length > 1 ? 2 : 0
  const starts = visible.map((_, i) => visible.slice(0, i).reduce((sum, d) => sum + (d.value / total) * circ, 0))
  const active = data.find((d) => d.key === hover)

  return (
    <div className={clsx('flex gap-x-6 gap-y-4 p-4', layout === 'stacked' ? 'flex-col items-stretch' : 'flex-wrap items-center')} onMouseLeave={() => setHover(null)}>
      <div className="relative mx-auto shrink-0" style={{ width: size, height: size }}>
        <svg viewBox={`0 0 ${size} ${size}`} className="size-full -rotate-90" role="img" aria-label={`Diagram ${totalLabel}: ${data.map((d) => `${d.label} ${d.value}`).join(', ')}`}>
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={TRACK} strokeWidth={stroke} />
          {visible.map((d, i) => {
            const len = (d.value / total) * circ
            return (
              <circle
                key={d.key}
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={d.color}
                strokeWidth={hover === d.key ? stroke + 4 : stroke}
                strokeDasharray={`${Math.max(0.5, len - gap)} ${circ}`}
                strokeDashoffset={-starts[i]}
                opacity={hover && hover !== d.key ? 0.45 : 1}
                className="cursor-default transition-opacity"
                onMouseEnter={() => setHover(d.key)}
              />
            )
          })}
        </svg>
        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center" aria-live="polite">
          <div className="max-w-[90px]">
            <p className="font-display text-xl font-semibold tabular-nums text-slate-900">{(active?.value ?? total).toLocaleString('id-ID')}</p>
            <p className="truncate text-[11px] text-slate-500">{active?.label ?? totalLabel}</p>
          </div>
        </div>
      </div>
      <ul className="min-w-[150px] flex-1 space-y-1.5 text-sm">
        {data.map((d) => (
          <li key={d.key} className={clsx('flex items-center gap-2 rounded px-1 transition-colors', hover === d.key && 'bg-slate-50')} onMouseEnter={() => setHover(d.key)}>
            <Swatch color={d.color} />
            {d.to ? (
              <Link to={d.to} className="min-w-0 flex-1 truncate text-slate-700 hover:text-brand-700 hover:underline">
                {d.label}
              </Link>
            ) : (
              <span className="min-w-0 flex-1 truncate text-slate-700">{d.label}</span>
            )}
            <span className="font-medium tabular-nums text-slate-900">{d.value.toLocaleString('id-ID')}</span>
            <span className="w-11 text-right text-xs tabular-nums text-slate-500">{formatPercent((d.value / total) * 100)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Half-circle meter for one ratio (0–100%) on a same-ramp track. */
export function Gauge({ value, label, note }: { value: number | null; label: string; note?: string }) {
  const w = 140
  const stroke = 14
  const r = (w - stroke) / 2
  const cy = r + stroke / 2
  const arc = `M${stroke / 2},${cy} A${r},${r} 0 0 1 ${w - stroke / 2},${cy}`
  const len = Math.PI * r
  const pct = value === null ? 0 : Math.min(100, Math.max(0, value))

  return (
    <div className="flex flex-col items-center text-center" title={value === null ? `${label}: belum ada data` : `${label}: ${formatPercent(pct)}`}>
      <div className="relative" style={{ width: w, height: cy + 2 }}>
        <svg viewBox={`0 0 ${w} ${cy + 2}`} className="size-full" role="meter" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={value === null ? undefined : Math.round(pct)}>
          <path d={arc} fill="none" stroke={TRACK} strokeWidth={stroke} strokeLinecap="round" />
          {pct > 0 && <path d={arc} fill="none" stroke={SERIES.one} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={`${(pct / 100) * len} ${len}`} />}
        </svg>
        <p className="absolute inset-x-0 bottom-0 font-display text-2xl font-semibold tabular-nums text-slate-900">{value === null ? '—' : formatPercent(pct)}</p>
      </div>
      <p className="mt-1.5 text-sm font-medium text-slate-700">{label}</p>
      {note && <p className="text-xs text-slate-500">{note}</p>}
    </div>
  )
}

/** One horizontal 100% bar split into parts, with a legend that prints every value (optional when the rows beside it already do). */
export function StackedBar({ data, emptyText = 'Belum ada data.', legend = true, format = (v) => v.toLocaleString('id-ID') }: { data: SliceDatum[]; emptyText?: string; legend?: boolean; format?: (value: number) => string }) {
  const total = data.reduce((sum, d) => sum + d.value, 0)
  if (total === 0) return <p className="text-sm text-slate-500">{emptyText}</p>
  return (
    <div>
      <div className="flex h-3.5 w-full gap-0.5 overflow-hidden rounded-full" role="img" aria-label={data.map((d) => `${d.label} ${format(d.value)}`).join(', ')}>
        {data
          .filter((d) => d.value > 0)
          .map((d) => (
            <div key={d.key} className="h-full first:rounded-l-full last:rounded-r-full" style={{ width: `${(d.value / total) * 100}%`, backgroundColor: d.color }} title={`${d.label}: ${format(d.value)}`} />
          ))}
      </div>
      {legend && <ul className="mt-2.5 grid grid-cols-1 gap-1 text-sm">
        {data.map((d) => (
          <li key={d.key} className="flex items-center gap-2">
            <Swatch color={d.color} />
            {d.to ? (
              <Link to={d.to} className="min-w-0 flex-1 truncate text-slate-700 hover:text-brand-700 hover:underline">
                {d.label}
              </Link>
            ) : (
              <span className="min-w-0 flex-1 truncate text-slate-700">{d.label}</span>
            )}
            <span className="shrink-0 font-medium tabular-nums text-slate-900">{format(d.value)}</span>
          </li>
        ))}
      </ul>}
    </div>
  )
}

/** Tiny single-series columns for a stat tile: the shape of the series, exact values in each column's tooltip. */
export function MiniColumns({ data, label, unit = '' }: { data: BarDatum[]; label: string; unit?: string }) {
  const width = 160
  const height = 48
  const gap = 2
  const max = Math.max(1, ...data.map((d) => d.value))
  const band = width / Math.max(1, data.length)
  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="h-12 w-full" preserveAspectRatio="none" role="img" aria-label={`${label}: ${data.map((d) => `${d.label} ${d.value}`).join(', ')}`}>
      <line x1={0} x2={width} y1={height - 0.5} y2={height - 0.5} stroke="#cbd5e1" />
      {data.map((d, i) => {
        const h = (d.value / max) * (height - 2)
        return (
          <g key={d.key}>
            <title>{`${d.label}: ${d.value.toLocaleString('id-ID')} ${unit}`.trimEnd()}</title>
            {d.value > 0 && <rect x={i * band + gap / 2} y={height - h - 1} width={band - gap} height={h} fill={SERIES.one} />}
            <rect x={i * band} y={0} width={band} height={height} fill="transparent" />
          </g>
        )
      })}
    </svg>
  )
}

/**
 * Single-series columns over ordered classes (e.g. age buckets). Few columns, so each is
 * labeled with its value; hovering a column highlights its band.
 */
export function ColumnChart({ data, label, unit = 'aset' }: { data: BarDatum[]; label: string; unit?: string }) {
  const [hover, setHover] = useState<number | null>(null)
  const width = 320
  const height = 180
  const pad = { top: 18, right: 4, bottom: 34, left: 4 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom
  const max = Math.max(1, ...data.map((d) => d.value))
  const band = plotW / data.length
  const barW = Math.min(36, band * 0.6)
  const base = pad.top + plotH

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label={`${label}: ${data.map((d) => `${d.label} ${d.value}`).join(', ')}`} onMouseLeave={() => setHover(null)}>
      <line x1={pad.left} x2={width - pad.right} y1={base} y2={base} stroke="#cbd5e1" />
      {data.map((d, i) => {
        const x0 = pad.left + i * band
        const x = x0 + (band - barW) / 2
        const h = (d.value / max) * plotH
        const top = base - h
        const r = Math.min(4, h, barW / 2)
        return (
          <g key={d.key} onMouseEnter={() => setHover(i)}>
            <title>{`${d.label}: ${d.value.toLocaleString('id-ID')} ${unit}`}</title>
            {hover === i && <rect x={x0} y={pad.top - 14} width={band} height={plotH + 14} fill="#f1f5f9" />}
            {d.value > 0 && <path d={`M${x},${base} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${base} Z`} fill={SERIES.one} />}
            <text x={x0 + band / 2} y={top - 5} textAnchor="middle" fontSize={11} fontWeight={600} fill="#0f172a">
              {d.value.toLocaleString('id-ID')}
            </text>
            <text x={x0 + band / 2} y={height - 14} textAnchor="middle" fontSize={10.5} fill="#64748b">
              {d.label}
            </text>
            <rect x={x0} y={pad.top - 14} width={band} height={plotH + 34} fill="transparent" />
          </g>
        )
      })}
    </svg>
  )
}

export interface BarDatum {
  key: string
  label: string
  value: number
  /** Optional drill-down link (e.g. the asset list filtered by this category). */
  to?: string
}

type ListColumns = 1 | 2

const LIST_COLUMNS: Record<ListColumns, string> = {
  1: 'space-y-2.5',
  2: 'grid grid-cols-2 gap-x-6 gap-y-2.5',
}

/** Horizontal bars for one measure across categories, sorted by the caller. Values are printed, so the list doubles as its table. */
export function BarList({
  data: all,
  emptyText = 'Belum ada data.',
  unit = 'aset',
  format = (v) => v.toLocaleString('id-ID'),
  limit,
  columns = 1,
}: {
  data: BarDatum[]
  emptyText?: string
  unit?: string
  format?: (value: number) => string
  /** Show at most this many rows; the rest fold into one "Lainnya" row. */
  limit?: number
  columns?: ListColumns
}) {
  const data = limit && all.length > limit ? [...all.slice(0, limit - 1), { key: '__other', label: `Lainnya (${all.length - limit + 1})`, value: all.slice(limit - 1).reduce((s, d) => s + d.value, 0) }] : all
  const max = Math.max(1, ...data.map((d) => d.value))
  if (data.length === 0 || data.every((d) => d.value === 0)) return <p className="p-4 text-sm text-slate-500">{emptyText}</p>

  return (
    <ul className={clsx('p-4', LIST_COLUMNS[columns])}>
      {data.map((d) => {
        const label = d.to ? (
          <Link to={d.to} className="truncate text-slate-700 hover:text-brand-700 hover:underline">
            {d.label}
          </Link>
        ) : (
          <span className="truncate text-slate-700">{d.label}</span>
        )
        return (
          <li key={d.key} title={`${d.label}: ${format(d.value)} ${unit}`.trimEnd()}>
            <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
              {label}
              <span className="shrink-0 font-medium tabular-nums text-slate-900">{format(d.value)}</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100" aria-hidden>
              <div className="h-full rounded-full" style={{ width: `${Math.max(d.value > 0 ? 1.5 : 0, (d.value / max) * 100)}%`, backgroundColor: SERIES.one }} />
            </div>
          </li>
        )
      })}
    </ul>
  )
}

export interface MeterDatum {
  key: string
  label: string
  part: number
  total: number
}

/** Share of a whole per row (e.g. data completeness per field): percentage printed, count in the tooltip. */
export function MeterList({ data, columns = 1 }: { data: MeterDatum[]; columns?: ListColumns }) {
  return (
    <ul className={clsx('p-4', LIST_COLUMNS[columns])}>
      {data.map((d) => {
        const pct = d.total > 0 ? (d.part / d.total) * 100 : 0
        return (
          <li key={d.key} title={`${d.label}: ${d.part} dari ${d.total}`}>
            <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
              <span className="truncate text-slate-700">{d.label}</span>
              <span className="shrink-0 font-medium tabular-nums text-slate-900">{formatPercent(pct)}</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100" role="meter" aria-label={d.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(pct)}>
              <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: SERIES.one }} />
            </div>
          </li>
        )
      })}
    </ul>
  )
}

export interface TrendPoint {
  month: string // YYYY-MM
  added: number
  changes: number
}

/**
 * Grouped columns per month: assets added vs. other changes. One y-axis (both are counts),
 * legend + hover tooltip per month, and a table view for screen readers and exact values.
 */
export function TrendChart({ data, compact = false }: { data: TrendPoint[]; /** Drawing size for a narrower card, so axis text isn't scaled down with it. */ compact?: boolean }) {
  const [hover, setHover] = useState<number | null>(null)
  const [showTable, setShowTable] = useState(false)

  const width = compact ? 440 : 640
  const height = compact ? 200 : 220
  const pad = { top: 12, right: 8, bottom: 26, left: 32 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom
  const rawMax = Math.max(1, ...data.flatMap((d) => [d.added, d.changes]))
  const step = Math.max(1, Math.ceil(rawMax / 4))
  const max = step * 4
  const band = plotW / data.length
  const barW = Math.min(12, (band - 8) / 2)
  const y = (v: number) => pad.top + plotH - (v / max) * plotH

  /** Column with a 4px rounded top, square on the baseline. */
  const column = (x: number, value: number, color: string) => {
    if (value === 0) return null
    const top = y(value)
    const h = pad.top + plotH - top
    const r = Math.min(4, h, barW / 2)
    const d = `M${x},${pad.top + plotH} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${pad.top + plotH} Z`
    return <path d={d} fill={color} />
  }

  return (
    <div className="p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3 text-sm">
        <div className="flex gap-4 text-slate-700">
          <LegendItem color={SERIES.one} label="Aset ditambahkan" />
          <LegendItem color={SERIES.two} label="Perubahan (pindah, status, pinjam, dll.)" />
        </div>
        <button type="button" className="text-xs font-medium text-brand-700 hover:underline" onClick={() => setShowTable((v) => !v)} aria-expanded={showTable}>
          {showTable ? 'Tampilkan grafik' : 'Tampilkan tabel'}
        </button>
      </div>

      {showTable ? (
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500">
              <th className="py-1 font-medium">Bulan</th>
              <th className="py-1 text-right font-medium">Ditambahkan</th>
              <th className="py-1 text-right font-medium">Perubahan</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data.map((d) => (
              <tr key={d.month}>
                <td className="py-1 text-slate-700">{monthLongLabel(d.month)}</td>
                <td className="py-1 text-right tabular-nums text-slate-900">{d.added}</td>
                <td className="py-1 text-right tabular-nums text-slate-900">{d.changes}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <div className="relative">
          <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label="Grafik jumlah aset ditambahkan dan perubahan aset per bulan, 12 bulan terakhir" onMouseLeave={() => setHover(null)}>
            {[0, 1, 2, 3, 4].map((i) => (
              <g key={i}>
                <line x1={pad.left} x2={width - pad.right} y1={y(i * step)} y2={y(i * step)} stroke={i === 0 ? '#cbd5e1' : '#eef2f6'} strokeWidth={1} />
                <text x={pad.left - 6} y={y(i * step) + 4} textAnchor="end" fontSize={10} fill="#64748b">
                  {i * step}
                </text>
              </g>
            ))}
            {data.map((d, i) => {
              const x0 = pad.left + i * band
              const gx = x0 + (band - (barW * 2 + 2)) / 2
              return (
                <g key={d.month}>
                  {hover === i && <rect x={x0} y={pad.top} width={band} height={plotH} fill="#f1f5f9" />}
                  {column(gx, d.added, SERIES.one)}
                  {column(gx + barW + 2, d.changes, SERIES.two)}
                  <text x={x0 + band / 2} y={height - 8} textAnchor="middle" fontSize={10} fill="#64748b">
                    {monthShortLabel(d.month)}
                  </text>
                  {/* Hit target: the whole month band, larger than the bars. */}
                  <rect x={x0} y={pad.top} width={band} height={plotH} fill="transparent" onMouseEnter={() => setHover(i)} />
                </g>
              )
            })}
          </svg>
          {hover !== null && (
            <div
              className="pointer-events-none absolute top-2 z-10 rounded-md bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-200"
              style={{ left: `${Math.min(80, ((hover + 0.5) / data.length) * 100)}%` }}
              role="status"
            >
              <p className="mb-1 font-semibold text-slate-900">{monthLongLabel(data[hover].month)}</p>
              <p className="flex items-center gap-1.5 text-slate-700">
                <Swatch color={SERIES.one} /> Ditambahkan: <span className="font-medium tabular-nums text-slate-900">{data[hover].added}</span>
              </p>
              <p className="flex items-center gap-1.5 text-slate-700">
                <Swatch color={SERIES.two} /> Perubahan: <span className="font-medium tabular-nums text-slate-900">{data[hover].changes}</span>
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function LegendItem({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <Swatch color={color} />
      {label}
    </span>
  )
}

export function Swatch({ color }: { color: string }) {
  return <span className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: color }} aria-hidden />
}
