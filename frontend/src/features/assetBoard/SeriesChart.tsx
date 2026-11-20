import { useState } from 'react'
import { Swatch } from '../dashboard/charts'
import { CATEGORICAL } from '../dashboard/palette'

export interface Series {
  key: string
  label: string
  data: number[]
}

/**
 * Columns (grouped per category) or lines for one or more series that share a unit. The
 * values are also in a visually hidden table, and hovering a category shows its values.
 */
export function SeriesChart({ categories, series, kind, format, categoryLabel = (c) => c }: { categories: string[]; series: Series[]; kind: 'bar' | 'line'; format: (v: number) => string; categoryLabel?: (c: string) => string }) {
  const [hover, setHover] = useState<number | null>(null)
  const width = 640
  const height = 220
  const pad = { top: 12, right: 8, bottom: 28, left: 8 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom
  const max = Math.max(1, ...series.flatMap((s) => s.data))
  const step = plotW / Math.max(1, categories.length)
  const y = (v: number) => pad.top + plotH - (v / max) * plotH
  const groupW = step * 0.7
  const barW = groupW / Math.max(1, series.length)
  const labelEvery = Math.ceil(categories.length / 12)
  const color = (i: number) => CATEGORICAL[i % CATEGORICAL.length]

  return (
    <div>
      {series.length > 1 && (
        <ul className="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
          {series.map((s, i) => (
            <li key={s.key} className="flex items-center gap-1.5">
              <Swatch color={color(i)} />
              {s.label}
            </li>
          ))}
        </ul>
      )}
      <div className="relative">
        <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label={series.map((s) => s.label).join(', ')} onMouseLeave={() => setHover(null)}>
          <line x1={pad.left} x2={width - pad.right} y1={pad.top + plotH} y2={pad.top + plotH} stroke="#e2e8f0" />
          {categories.map((c, ci) => {
            const x0 = pad.left + ci * step
            return (
              <g key={c} onMouseEnter={() => setHover(ci)}>
                <rect x={x0} y={pad.top} width={step} height={plotH} fill={hover === ci ? '#f1f5f9' : 'transparent'} />
                {kind === 'bar' &&
                  series.map((s, si) => (
                    <rect key={s.key} x={x0 + (step - groupW) / 2 + si * barW} y={y(s.data[ci] ?? 0)} width={Math.max(1, barW - 2)} height={pad.top + plotH - y(s.data[ci] ?? 0)} rx={2} fill={color(si)} />
                  ))}
                {ci % labelEvery === 0 && (
                  <text x={x0 + step / 2} y={height - 8} textAnchor="middle" fontSize={11} fill="#64748b">
                    {categoryLabel(c)}
                  </text>
                )}
              </g>
            )
          })}
          {kind === 'line' &&
            series.map((s, si) => (
              <polyline
                key={s.key}
                fill="none"
                stroke={color(si)}
                strokeWidth={2.5}
                strokeLinejoin="round"
                points={s.data.map((v, i) => `${pad.left + i * step + step / 2},${y(v)}`).join(' ')}
              />
            ))}
        </svg>
        {hover !== null && (
          <div className="pointer-events-none absolute right-2 top-1 rounded-md bg-slate-900/90 px-2.5 py-1.5 text-xs text-white">
            <p className="font-semibold">{categoryLabel(categories[hover])}</p>
            {series.map((s) => (
              <p key={s.key}>
                {series.length > 1 && `${s.label}: `}
                {format(s.data[hover] ?? 0)}
              </p>
            ))}
          </div>
        )}
      </div>
      <table className="sr-only">
        <thead>
          <tr>
            <th>Periode</th>
            {series.map((s) => (
              <th key={s.key}>{s.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {categories.map((c, ci) => (
            <tr key={c}>
              <td>{categoryLabel(c)}</td>
              {series.map((s) => (
                <td key={s.key}>{format(s.data[ci] ?? 0)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
