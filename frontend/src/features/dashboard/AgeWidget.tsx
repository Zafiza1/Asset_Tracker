import { Link } from 'react-router'
import { formatDate, formatPercent } from '../../lib/format'
import { formatCount, sum } from './chartFormat'
import { ColumnChart, Swatch } from './charts'
import type { Dashboard } from './DashboardPage'
import { Kpi, Widget } from './widgetParts'
import { useWidgetSize } from './widgetSize'

type Age = Dashboard['age']
type AgeCategory = Age['by_category'][number]

/** Ordered age bands: one hue, darker is older (validated with --ordinal on the light surface). */
const AGE_RAMP: Record<string, string> = { lt1: '#86b4ec', '1_3': '#5c99e4', '3_5': '#3580d9', '5_10': '#2569bd', gte10: '#184a89' }

/** Bands that count as "due for a replacement review". */
const OLD_BUCKETS = ['5_10', 'gte10']

const STACKED_CATEGORY_LIMIT = 5

const formatYears = (years: number | null) => (years === null ? '—' : `${years.toLocaleString('id-ID')} tahun`)

/**
 * Asset age, drawn differently for each width:
 * 1/3 the distribution as columns; 1/2 the average plus each band's share;
 * 2/3 headline figures and how age is spread per category; full the whole analysis,
 * with a category × age grid and the oldest assets.
 */
export function AgeWidget({ age }: { age: Age }) {
  const size = useWidgetSize()
  const known = sum(age.buckets.map((b) => b.count))

  return (
    <Widget
      title="Umur aset"
      action={
        size === 'third' &&
        age.average_years !== null && (
          <span className="text-xs text-slate-500">
            Rata-rata <span className="font-semibold text-slate-900">{formatYears(age.average_years)}</span>
          </span>
        )
      }
    >
      {known === 0 ? (
        <p className="p-4 text-sm text-slate-500">Belum ada aset dengan tanggal perolehan.</p>
      ) : size === 'full' ? (
        <AgeFull age={age} known={known} />
      ) : size === 'two_thirds' ? (
        <AgeByCategory age={age} known={known} />
      ) : size === 'half' ? (
        <AgeShares age={age} known={known} />
      ) : (
        <div className="p-4">
          <AgeColumns age={age} />
        </div>
      )}
      {age.unknown > 0 && size !== 'full' && size !== 'two_thirds' && <p className="px-4 pb-4 text-xs text-slate-500">{formatCount(age.unknown)} aset tanpa tanggal perolehan tidak dihitung.</p>}
    </Widget>
  )
}

function AgeColumns({ age }: { age: Age }) {
  return <ColumnChart label="Jumlah aset per kelompok umur" data={age.buckets.map((b) => ({ key: b.key, label: b.label, value: b.count }))} />
}

const oldCount = (age: Age) => sum(age.buckets.filter((b) => OLD_BUCKETS.includes(b.key)).map((b) => b.count))

/** 1/2 width: the average as the headline, each band's share beside it. */
function AgeShares({ age, known }: { age: Age; known: number }) {
  const old = oldCount(age)
  const largest = Math.max(1, ...age.buckets.map((b) => b.count))
  return (
    <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] items-center gap-5 p-4">
      <div>
        <p className="text-xs font-medium text-slate-600">Rata-rata umur</p>
        <p className="mt-0.5 text-3xl font-semibold text-slate-900">{formatYears(age.average_years)}</p>
        <p className="mt-2 text-xs text-slate-600">
          <span className="font-semibold text-slate-900">{formatPercent((old / known) * 100)}</span> aset berumur 5 tahun ke atas
        </p>
      </div>
      <ul className="space-y-2">
        {age.buckets.map((b) => (
          <li key={b.key} title={`${b.label}: ${formatCount(b.count)} aset`}>
            <div className="mb-1 flex items-baseline justify-between gap-2 text-sm">
              <span className="truncate text-slate-700">{b.label}</span>
              <span className="shrink-0 tabular-nums">
                <span className="font-medium text-slate-900">{formatCount(b.count)}</span>
                <span className="ml-2 inline-block w-11 text-right text-xs text-slate-500">{formatPercent((b.count / known) * 100)}</span>
              </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden>
              <div className="h-full rounded-full" style={{ width: `${(b.count / largest) * 100}%`, backgroundColor: AGE_RAMP[b.key] }} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}

function AgeKpis({ age, known, withKnown = false }: { age: Age; known: number; withKnown?: boolean }) {
  const old = oldCount(age)
  return (
    <div className={withKnown ? 'grid grid-cols-4 gap-3' : 'grid grid-cols-3 gap-3'}>
      <Kpi label="Rata-rata umur" value={formatYears(age.average_years)} />
      {withKnown && <Kpi label="Aset dihitung" value={formatCount(known)} />}
      <Kpi label="Umur ≥ 5 tahun" value={formatCount(old)} note={`${formatPercent((old / known) * 100)} dari aset dihitung`} />
      <Kpi label="Tanpa tanggal perolehan" value={formatCount(age.unknown)} note="Tidak dihitung" />
    </div>
  )
}

/** The largest categories, the rest folded into one "Lainnya" row. */
function foldCategories(categories: AgeCategory[], age: Age): AgeCategory[] {
  if (categories.length <= STACKED_CATEGORY_LIMIT) return categories
  const shown = categories.slice(0, STACKED_CATEGORY_LIMIT - 1)
  const rest = categories.slice(STACKED_CATEGORY_LIMIT - 1)
  const total = sum(rest.map((c) => c.total))
  return [
    ...shown,
    {
      id: '__other',
      name: `Lainnya (${rest.length})`,
      total,
      average_years: Math.round((sum(rest.map((c) => c.average_years * c.total)) / total) * 10) / 10,
      buckets: Object.fromEntries(age.buckets.map((b) => [b.key, sum(rest.map((c) => c.buckets[b.key] ?? 0))])),
    },
  ]
}

/** 2/3 width: headline figures, then one 100% bar per category on a shared scale. */
function AgeByCategory({ age, known }: { age: Age; known: number }) {
  const rows = foldCategories(age.by_category, age)
  return (
    <div className="space-y-4 p-4">
      <AgeKpis age={age} known={known} />
      <div>
        <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-700" aria-label="Kelompok umur">
          {age.buckets.map((b) => (
            <li key={b.key} className="inline-flex items-center gap-1.5">
              <Swatch color={AGE_RAMP[b.key]} />
              {b.label}
            </li>
          ))}
        </ul>
        <ul className="space-y-2.5">
          {rows.map((c) => (
            <li key={c.id}>
              <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <CategoryName category={c} />
                <span className="shrink-0 text-xs text-slate-500">
                  {formatCount(c.total)} aset · rata-rata {formatYears(c.average_years)}
                </span>
              </div>
              <div className="flex h-3 gap-0.5 overflow-hidden rounded-full" role="img" aria-label={`${c.name}: ${age.buckets.map((b) => `${b.label} ${c.buckets[b.key] ?? 0}`).join(', ')}`}>
                {age.buckets
                  .filter((b) => (c.buckets[b.key] ?? 0) > 0)
                  .map((b) => (
                    <div key={b.key} style={{ width: `${((c.buckets[b.key] ?? 0) / c.total) * 100}%`, backgroundColor: AGE_RAMP[b.key] }} title={`${b.label}: ${formatCount(c.buckets[b.key] ?? 0)} aset`} />
                  ))}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function CategoryName({ category }: { category: AgeCategory }) {
  if (category.id === '__other') return <span className="truncate text-slate-700">{category.name}</span>
  return (
    <Link to={`/aset?category_ids=${category.id}`} className="truncate text-slate-700 hover:text-brand-700 hover:underline">
      {category.name}
    </Link>
  )
}

/** Sequential scale for the grid: one hue, more is darker; the text flips to stay readable. */
const HEAT_STEPS = [
  { background: '#cde2fb', color: '#0f172a' },
  { background: '#9ec5f4', color: '#0f172a' },
  { background: '#6da7ec', color: '#0f172a' },
  { background: '#256abf', color: '#ffffff' },
  { background: '#184f95', color: '#ffffff' },
]

function heatStyle(count: number, max: number) {
  if (count === 0 || max === 0) return undefined
  return HEAT_STEPS[Math.max(0, Math.ceil((count / max) * HEAT_STEPS.length) - 1)]
}

/** Column heads without the unit: the caption says the columns are in years. */
const shortBucketLabel = (label: string) => label.replace(' tahun', '')

/** Full width: figures, distribution, category × age grid and the oldest assets, all together. */
function AgeFull({ age, known }: { age: Age; known: number }) {
  const max = Math.max(0, ...age.by_category.flatMap((c) => age.buckets.map((b) => c.buckets[b.key] ?? 0)))
  return (
    <div className="space-y-4 p-4">
      <AgeKpis age={age} known={known} withKnown />
      <div className="grid grid-cols-12 gap-5">
        <div className="col-span-4 min-w-0">
          <h3 className="mb-2 text-sm font-medium text-slate-700">Sebaran umur</h3>
          <AgeColumns age={age} />
        </div>
        <div className="col-span-5 min-w-0">
          <h3 className="mb-2 text-sm font-medium text-slate-700">Umur per kategori</h3>
          <div className="max-h-64 overflow-y-auto overscroll-contain">
            <table className="w-full border-separate border-spacing-0.5 text-xs">
              <caption className="sr-only">Jumlah aset per kategori dan kelompok umur, dalam tahun</caption>
              <thead className="sticky top-0 bg-white">
                <tr className="text-slate-500">
                  <th scope="col" className="py-1 text-left font-medium">
                    Kategori
                  </th>
                  {age.buckets.map((b) => (
                    <th key={b.key} scope="col" className="w-10 py-1 text-center font-medium tabular-nums" title={b.label}>
                      {shortBucketLabel(b.label)}
                    </th>
                  ))}
                  <th scope="col" className="w-14 py-1 text-right font-medium" title="Rata-rata umur (tahun)">
                    Rata-rata
                  </th>
                </tr>
              </thead>
              <tbody>
                {age.by_category.map((c) => (
                  <tr key={c.id}>
                    <th scope="row" className="max-w-0 truncate py-1 pr-1 text-left font-normal" title={c.name}>
                      <CategoryName category={c} />
                    </th>
                    {age.buckets.map((b) => {
                      const count = c.buckets[b.key] ?? 0
                      return (
                        <td key={b.key} className="rounded py-1 text-center tabular-nums text-slate-400" style={heatStyle(count, max)} title={`${c.name}, ${b.label}: ${formatCount(count)} aset`}>
                          {formatCount(count)}
                        </td>
                      )
                    })}
                    <td className="py-1 text-right tabular-nums text-slate-900">{c.average_years.toLocaleString('id-ID')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-2 text-xs text-slate-500">Kolom dalam tahun. Warna lebih gelap berarti lebih banyak aset.</p>
        </div>
        <div className="col-span-3 min-w-0">
          <h3 className="mb-2 text-sm font-medium text-slate-700">Aset tertua</h3>
          <ol className="divide-y divide-slate-100">
            {age.oldest.map((item) => (
              <li key={item.asset.id} className="py-2 text-sm">
                <Link to={`/aset/${item.asset.id}`} className="block truncate font-medium text-slate-900 hover:text-brand-700" title={item.asset.name}>
                  {item.asset.name}
                </Link>
                <p className="truncate text-xs text-slate-500">
                  {formatYears(item.age_years)} · {formatDate(item.acquisition_date)}
                </p>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </div>
  )
}
