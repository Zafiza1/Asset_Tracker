import { useState } from 'react'
import { Link } from 'react-router'

/**
 * Small dependency-free charts for the dashboard.
 * Colors: categorical slots 1–2 of the validated reference palette (light surface);
 * magnitude bars use slot 1 only. Text never takes a series color.
 */
const SERIES = {
  one: '#2a78d6',
  two: '#eb6834',
} as const

export interface BarDatum {
  key: string
  label: string
  value: number
  /** Optional drill-down link (e.g. the asset list filtered by this category). */
  to?: string
}

/** Horizontal bars for one measure across categories, sorted by the caller. Values are printed, so the list doubles as its table. */
export function BarList({ data, emptyText = 'Belum ada data.', unit = 'aset' }: { data: BarDatum[]; emptyText?: string; unit?: string }) {
  const max = Math.max(1, ...data.map((d) => d.value))
  if (data.length === 0 || data.every((d) => d.value === 0)) return <p className="p-4 text-sm text-slate-500">{emptyText}</p>

  return (
    <ul className="space-y-2.5 p-4">
      {data.map((d) => {
        const label = d.to ? (
          <Link to={d.to} className="truncate text-slate-700 hover:text-brand-700 hover:underline">
            {d.label}
          </Link>
        ) : (
          <span className="truncate text-slate-700">{d.label}</span>
        )
        return (
          <li key={d.key} title={`${d.label}: ${d.value} ${unit}`}>
            <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
              {label}
              <span className="shrink-0 font-medium tabular-nums text-slate-900">{d.value.toLocaleString('id-ID')}</span>
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

export interface TrendPoint {
  month: string // YYYY-MM
  added: number
  changes: number
}

const monthShort = new Intl.DateTimeFormat('id-ID', { month: 'short' })
const monthLong = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })
const toDate = (month: string) => new Date(`${month}-01T00:00:00`)

/**
 * Grouped columns per month: assets added vs. other changes. One y-axis (both are counts),
 * legend + hover tooltip per month, and a table view for screen readers and exact values.
 */
export function TrendChart({ data }: { data: TrendPoint[] }) {
  const [hover, setHover] = useState<number | null>(null)
  const [showTable, setShowTable] = useState(false)

  const width = 640
  const height = 220
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
                <td className="py-1 text-slate-700">{monthLong.format(toDate(d.month))}</td>
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
                    {monthShort.format(toDate(d.month))}
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
              <p className="mb-1 font-semibold text-slate-900">{monthLong.format(toDate(data[hover].month))}</p>
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

function Swatch({ color }: { color: string }) {
  return <span className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: color }} aria-hidden />
}
