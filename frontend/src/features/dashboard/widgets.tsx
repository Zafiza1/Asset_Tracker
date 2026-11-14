import clsx from 'clsx'
import { ArrowUpRight, Boxes, CalendarPlus, History, Wallet } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Card } from '../../components/ui/Card'
import { formatDate, formatPercent } from '../../lib/format'
import { formatMoney } from '../assets/api'
import { transactionTypeLabel } from '../transactions/api'
import { AgeWidget } from './AgeWidget'
import { formatCount, monthLongLabel, monthShortLabel, sum } from './chartFormat'
import { BarList, ColumnChart, DonutChart, Gauge, MiniColumns, StackedBar, Swatch, TrendChart, type SliceDatum } from './charts'
import type { Dashboard } from './DashboardPage'
import type { WidgetKey, WidgetSize } from './layout'
import { CATEGORICAL, NEUTRAL, STATUS_COLOR } from './palette'
import { Kpi, Widget } from './widgetParts'
import { useWidgetSize } from './widgetSize'
import { SegmentedControl } from '../../components/ui/Tabs'

type AttentionReason = keyof Dashboard['attention']['counts']

const attentionLabel: Record<AttentionReason, string> = {
  overdue_loan: 'Pinjaman lewat jatuh tempo',
  under_maintenance: 'Dalam perbaikan',
  no_location: 'Tanpa lokasi',
}

/**
 * Every widget draws the same subject at each width, but shows more of it as it grows:
 * 1/3 the one chart that answers the question, 1/2 that answer with its figures,
 * 2/3 a side-by-side breakdown, full width the complete analysis. See useWidgetSize.
 */
export function DashboardWidget({ widgetKey, data }: { widgetKey: WidgetKey; data: Dashboard }) {
  switch (widgetKey) {
    case 'stat_total':
      return <TotalStat data={data} />
    case 'stat_value':
      return <ValueStat data={data} />
    case 'stat_added':
      return <AddedStat data={data} />
    case 'stat_transactions':
      return <TransactionsStat data={data} />
    case 'status':
      return <StatusWidget data={data} />
    case 'utilization':
      return <UtilizationWidget data={data} />
    case 'data_quality':
      return <DataQualityWidget quality={data.data_quality} />
    case 'trend':
      return <TrendWidget trend={data.trend} />
    case 'age':
      return <AgeWidget age={data.age} />
    case 'category':
      return <CategoryWidget data={data} />
    case 'location':
      return <LocationWidget locations={data.by_location} />
    case 'transaction_types':
      return <TransactionTypesWidget transactions={data.transactions} />
    case 'activity':
      return <ActivityWidget data={data} />
  }
}

/** Each status keeps its color whatever the sort order or width; past the palette it goes neutral rather than repeating a hue. */
function statusSlices(data: Dashboard): SliceDatum[] {
  return data.by_status.map((s, i) => ({ key: s.status, label: s.label, value: s.count, color: i < CATEGORICAL.length ? CATEGORICAL[i] : NEUTRAL, to: `/aset?status=${s.status}` }))
}

/**
 * How the status breakdown relates to the "Total aset" tile: every status of the non-archived
 * assets, so the parts add up to that figure. Warns if they ever don't (e.g. an unknown stored status).
 */
function StatusTotalNote({ data, total }: { data: Dashboard; total: number }) {
  const { archived } = data.summary
  if (total !== data.summary.total) {
    return (
      <p className="mx-4 mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
        Jumlah per status ({formatCount(total)}) berbeda dari total aset ({formatCount(data.summary.total)}). Ada aset dengan status yang tidak dikenali; periksa di Data Aset.
      </p>
    )
  }
  return (
    <p className="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
      Jumlah seluruh status = <span className="font-semibold tabular-nums text-slate-700">{formatCount(total)}</span> total aset
      {archived > 0 && <> · {formatCount(archived)} aset nonaktif (diarsipkan) tidak dihitung</>}
    </p>
  )
}

function StatusWidget({ data }: { data: Dashboard }) {
  const size = useWidgetSize()
  const slices = statusSlices(data)
  const total = sum(slices.map((s) => s.value))

  if (size === 'third' || size === 'half' || total === 0) {
    return (
      <Widget title="Status aset">
        <DonutChart data={slices} totalLabel="total aset" layout={size === 'half' ? 'row' : 'stacked'} />
        {total > 0 && <StatusTotalNote data={data} total={total} />}
      </Widget>
    )
  }
  const ranked = [...slices].sort((a, b) => b.value - a.value)
  return (
    <Widget title="Status aset">
      <div className="p-4 pb-0">
        <StackedBar data={slices} legend={false} />
      </div>
      {size === 'two_thirds' ? (
        <BarList columns={2} data={ranked.map(({ key, label, value, to }) => ({ key, label, value, to }))} />
      ) : (
        <ul className="grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-3 p-4">
          {slices.map((s) => (
            <li key={s.key} className="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5">
              <Link to={s.to!} className="flex items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-brand-700 hover:underline">
                <Swatch color={s.color} />
                <span className="truncate">{s.label}</span>
              </Link>
              <p className="mt-1 text-xl font-semibold text-slate-900">{formatCount(s.value)}</p>
              <p className="text-xs text-slate-500">{formatPercent((s.value / total) * 100)}</p>
            </li>
          ))}
        </ul>
      )}
      <StatusTotalNote data={data} total={total} />
    </Widget>
  )
}

type UsageRow = SliceDatum & { status: string; hint: string }

/**
 * The operable assets split by what they are doing, in plain words, each tied to the stored
 * status it counts (labels differ: "Aktif" means in use, "Tidak Aktif" means idle/available).
 * Colors match the same statuses in "Status aset".
 */
function usageRows(data: Dashboard): UsageRow[] {
  const color = Object.fromEntries(statusSlices(data).map((s) => [s.key, s.color]))
  const label = Object.fromEntries(data.by_status.map((s) => [s.status, s.label]))
  const u = data.utilization
  const row = (key: string, name: string, value: number, hint: string): UsageRow => ({ key, label: name, status: label[key] ?? key, value, hint, color: color[key] ?? NEUTRAL, to: `/aset?status=${key}` })
  return [
    row('in_use', 'Digunakan', u.in_use, 'Sedang dipakai sendiri oleh unit/pegawai.'),
    row('on_loan', 'Dipinjam', u.on_loan, 'Dipinjamkan ke pihak lain lewat transaksi peminjaman.'),
    row('available', 'Tersedia', u.available, 'Siap dipakai, tetapi saat ini tidak digunakan.'),
    row('under_maintenance', 'Dalam perbaikan', u.under_maintenance, 'Sementara tidak dapat dipakai.'),
  ]
}

function UsageBreakdown({ data, detailed = false }: { data: Dashboard; detailed?: boolean }) {
  const { operable, excluded } = data.utilization
  const rows = usageRows(data)
  return (
    <div className="min-w-0">
      <StackedBar data={rows} legend={false} emptyText="Belum ada aset operasional." />
      <ul className="mt-3 space-y-2 text-sm">
        {rows.map((r) => (
          <li key={r.key} className="min-w-0">
            <div className="flex items-center gap-2">
              <Swatch color={r.color} />
              <Link to={r.to!} className="min-w-0 flex-1 truncate text-slate-700 hover:text-brand-700 hover:underline">
                {r.label} <span className="text-xs text-slate-500">(status {r.status})</span>
              </Link>
              <span className="shrink-0 font-medium tabular-nums text-slate-900">{formatCount(r.value)}</span>
              <span className="w-11 shrink-0 text-right text-xs tabular-nums text-slate-500">{operable > 0 ? formatPercent((r.value / operable) * 100) : '—'}</span>
            </div>
            {detailed && <p className="ml-5 text-xs text-slate-500">{r.hint}</p>}
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-slate-500">
        Pemanfaatan = (Digunakan + Dipinjam) ÷ {formatCount(operable)} aset operasional.
        {excluded > 0 && <> {formatCount(excluded)} aset hilang/dihapuskan tidak dihitung.</>}
      </p>
    </div>
  )
}

function UtilizationWidget({ data }: { data: Dashboard }) {
  const size = useWidgetSize()
  const { utilization, loans } = data
  const onTime = loans.active - loans.overdue - loans.due_soon
  const loanData: SliceDatum[] = [
    { key: 'overdue', label: 'Terlambat', value: loans.overdue, color: STATUS_COLOR.critical },
    { key: 'due_soon', label: `Jatuh tempo ≤ ${loans.due_soon_days} hari`, value: loans.due_soon, color: STATUS_COLOR.warning },
    { key: 'on_time', label: 'Belum jatuh tempo', value: onTime, color: STATUS_COLOR.good },
  ]
  const gauge = <Gauge value={utilization.rate} label="Tingkat pemanfaatan" note={`${formatCount(utilization.in_service)} dari ${formatCount(utilization.operable)} aset operasional digunakan atau dipinjam`} />
  const loanBlock = (
    <div className="min-w-0">
      <p className="mb-2 flex items-baseline justify-between gap-2 text-sm">
        <span className="font-medium text-slate-700">Jatuh tempo aset dipinjam</span>
        <Link to="/aset?status=on_loan" className="font-semibold tabular-nums text-slate-900 hover:text-brand-700">
          {formatCount(loans.active)}
        </Link>
      </p>
      <StackedBar data={loanData} emptyText="Tidak ada aset yang sedang dipinjam." />
    </div>
  )

  if (size === 'third') {
    return (
      <Widget title="Pemanfaatan & peminjaman">
        <div className="space-y-5 p-4">
          {gauge}
          <UsageBreakdown data={data} />
          {loans.active > 0 && loanBlock}
        </div>
      </Widget>
    )
  }
  if (size === 'half') {
    return (
      <Widget title="Pemanfaatan & peminjaman">
        <div className="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-5 p-4">
          {gauge}
          <UsageBreakdown data={data} />
          <div className="col-span-2">{loanBlock}</div>
        </div>
      </Widget>
    )
  }
  if (size === 'two_thirds') {
    return (
      <Widget title="Pemanfaatan & peminjaman">
        <div className="grid grid-cols-[auto_minmax(0,1.3fr)_minmax(0,1fr)] items-start gap-5 p-4">
          {gauge}
          <UsageBreakdown data={data} detailed />
          {loanBlock}
        </div>
      </Widget>
    )
  }
  const overdue = data.attention.items.filter((i) => i.reason === 'overdue_loan')
  return (
    <Widget title="Pemanfaatan & peminjaman">
      <div className="grid grid-cols-[auto_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)] items-start gap-5 p-4">
        {gauge}
        <UsageBreakdown data={data} detailed />
        {loanBlock}
        <div className="min-w-0">
          <h3 className="mb-2 text-sm font-medium text-slate-700">Pinjaman terlambat</h3>
          {overdue.length === 0 ? (
            <p className="text-sm text-slate-500">Tidak ada pinjaman yang terlambat.</p>
          ) : (
            <ul className="max-h-44 divide-y divide-slate-100 overflow-y-auto overscroll-contain">
              {overdue.map((item) => (
                <li key={item.asset.id} className="py-1.5 text-sm">
                  <Link to={`/aset/${item.asset.id}`} className="block truncate font-medium text-slate-900 hover:text-brand-700">
                    {item.asset.name}
                  </Link>
                  <p className="truncate text-xs text-slate-500">
                    {item.borrower_name ?? 'Peminjam tidak dicatat'} · {formatDate(item.loan_due_date)}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Widget>
  )
}

/** Which optional columns are left empty and on how many assets, most empty first; each links to those assets. */
function MissingFields({ quality, columns = 1 }: { quality: Dashboard['data_quality']; columns?: 1 | 2 }) {
  const fields = quality.fields.map((f) => ({ ...f, empty: quality.total - f.filled })).sort((a, b) => b.empty - a.empty)
  const missing = fields.filter((f) => f.empty > 0)
  const complete = fields.filter((f) => f.empty === 0)
  return (
    <div className="min-w-0">
      {missing.length === 0 ? (
        <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Semua kolom opsional sudah terisi.</p>
      ) : (
        <>
          <p className="mb-2 text-xs font-medium text-slate-600">Kolom yang belum lengkap</p>
          <ul className={clsx('gap-x-6 gap-y-2.5', columns === 2 ? 'grid grid-cols-2' : 'space-y-2.5')}>
            {missing.map((f) => {
              const pct = quality.total > 0 ? (f.filled / quality.total) * 100 : 0
              return (
                <li key={f.key} className="min-w-0 text-sm">
                  <div className="mb-1 flex items-baseline justify-between gap-3">
                    <span className="truncate text-slate-700">{f.label}</span>
                    <Link to={`/aset?missing=${f.key}`} className="inline-flex shrink-0 items-center gap-0.5 font-medium tabular-nums text-amber-800 hover:text-brand-700 hover:underline" title={`Lihat ${f.empty} aset tanpa ${f.label.toLowerCase()}`}>
                      {formatCount(f.empty)} kosong
                      <ArrowUpRight className="size-3.5" aria-hidden />
                    </Link>
                  </div>
                  <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100" role="meter" aria-label={`${f.label} terisi`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(pct)}>
                    <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: CATEGORICAL[0] }} />
                  </div>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {formatCount(f.filled)} dari {formatCount(quality.total)} terisi ({formatPercent(pct)})
                  </p>
                </li>
              )
            })}
          </ul>
        </>
      )}
      {complete.length > 0 && missing.length > 0 && <p className="mt-3 text-xs text-slate-500">Sudah lengkap: {complete.map((f) => f.label).join(', ')}.</p>}
    </div>
  )
}

function DataQualityWidget({ quality }: { quality: Dashboard['data_quality'] }) {
  const size = useWidgetSize()
  const incomplete = quality.total - quality.complete
  const gauge = <Gauge value={quality.score} label="Skor kelengkapan" note={`Rata-rata ${quality.fields.length} kolom opsional terisi`} />
  const summary = (
    <p className="rounded-lg bg-slate-50 px-3 py-2 text-center text-sm text-slate-700">
      <span className="font-semibold tabular-nums text-slate-900">{formatCount(quality.complete)}</span> dari {formatCount(quality.total)} aset lengkap
      {incomplete > 0 && (
        <>
          {' · '}
          <span className="font-semibold tabular-nums text-amber-800">{formatCount(incomplete)}</span> belum
        </>
      )}
    </p>
  )

  if (size === 'third') {
    return (
      <Widget title="Kelengkapan data">
        <div className="space-y-4 p-4">
          {gauge}
          {summary}
          <MissingFields quality={quality} />
        </div>
      </Widget>
    )
  }
  if (size === 'half' || size === 'two_thirds') {
    return (
      <Widget title="Kelengkapan data">
        <div className="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-5 p-4">
          <div className="space-y-3">
            {gauge}
            {summary}
          </div>
          <MissingFields quality={quality} columns={size === 'two_thirds' ? 2 : 1} />
        </div>
      </Widget>
    )
  }
  const share = (filled: number) => (quality.total > 0 ? (filled / quality.total) * 100 : 0)
  const worstFirst = [...quality.fields].sort((a, b) => a.filled - b.filled)
  return (
    <Widget title="Kelengkapan data">
      <div className="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-6 p-4">
        <div className="space-y-3">
          {gauge}
          {summary}
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-500">
              <th className="py-1 font-medium">Kolom</th>
              <th className="w-20 py-1 text-right font-medium">Terisi</th>
              <th className="w-28 py-1 text-right font-medium">Kosong</th>
              <th className="w-20 py-1 text-right font-medium">Persen</th>
              <th className="w-1/3 py-1 pl-4 font-medium">Kelengkapan</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {worstFirst.map((f) => {
              const empty = quality.total - f.filled
              return (
                <tr key={f.key}>
                  <td className="py-1.5 text-slate-700">{f.label}</td>
                  <td className="py-1.5 text-right tabular-nums text-slate-900">{formatCount(f.filled)}</td>
                  <td className="py-1.5 text-right tabular-nums">
                    {empty > 0 ? (
                      <Link to={`/aset?missing=${f.key}`} className="inline-flex items-center gap-0.5 font-medium text-amber-800 hover:text-brand-700 hover:underline" title={`Lihat ${empty} aset tanpa ${f.label.toLowerCase()}`}>
                        {formatCount(empty)}
                        <ArrowUpRight className="size-3.5" aria-hidden />
                      </Link>
                    ) : (
                      <span className="text-slate-500">0</span>
                    )}
                  </td>
                  <td className="py-1.5 text-right tabular-nums text-slate-900">{formatPercent(share(f.filled))}</td>
                  <td className="py-1.5 pl-4">
                    <div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                      <div className="h-full rounded-full" style={{ width: `${share(f.filled)}%`, backgroundColor: CATEGORICAL[0] }} />
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Widget>
  )
}

function TrendWidget({ trend }: { trend: Dashboard['trend'] }) {
  const size = useWidgetSize()
  const totalAdded = sum(trend.map((t) => t.added))
  const totalChanges = sum(trend.map((t) => t.changes))
  const recent = trend.slice(-6)

  if (size === 'third') {
    return (
      <Widget title="Aset ditambahkan (6 bulan)">
        <div className="p-4">
          <ColumnChart label="Aset ditambahkan per bulan, 6 bulan terakhir" data={recent.map((t) => ({ key: t.month, label: monthShortLabel(t.month), value: t.added }))} />
        </div>
      </Widget>
    )
  }
  if (size === 'half') {
    return (
      <Widget title="Penambahan & perubahan aset (6 bulan)">
        <TrendChart data={recent} compact />
      </Widget>
    )
  }
  if (size === 'two_thirds') {
    return (
      <Widget title="Penambahan & perubahan aset (12 bulan)">
        <TrendChart data={trend} />
      </Widget>
    )
  }
  const busiest = trend.reduce<Dashboard['trend'][number] | null>((best, t) => (!best || t.added + t.changes > best.added + best.changes ? t : best), null)
  return (
    <Widget title="Penambahan & perubahan aset (12 bulan)">
      <div className="grid grid-cols-[minmax(0,1fr)_14rem] items-start">
        <TrendChart data={trend} />
        <div className="space-y-3 p-4 pl-0">
          <Kpi label="Aset ditambahkan" value={formatCount(totalAdded)} note="12 bulan terakhir" />
          <Kpi label="Perubahan aset" value={formatCount(totalChanges)} note="12 bulan terakhir" />
          {busiest && <Kpi label="Bulan tersibuk" value={monthLongLabel(busiest.month)} note={`${formatCount(busiest.added + busiest.changes)} aktivitas`} />}
        </div>
      </div>
    </Widget>
  )
}

/** Count and acquisition value per category: a toggle while narrow, both at once when there is room. */
function CategoryWidget({ data }: { data: Dashboard }) {
  const size = useWidgetSize()
  const [measure, setMeasure] = useState<'count' | 'value'>('count')
  const countRows = data.by_category.map((c) => ({ key: c.id, label: c.name, value: c.count, to: `/aset?category_ids=${c.id}` }))
  const valueRows = data.value_by_category.map((c) => ({ key: c.id, label: c.name, value: Number(c.value), to: `/aset?category_ids=${c.id}` }))
  const emptyValue = 'Belum ada aset dengan nilai perolehan.'

  if (size === 'third' || size === 'half') {
    const limit = size === 'third' ? 5 : 8
    const toggle = (
      <SegmentedControl
        size="sm"
        label="Ukuran"
        value={measure}
        onChange={setMeasure}
        options={[
          { value: 'count', label: 'Jumlah' },
          { value: 'value', label: 'Nilai' },
        ]}
      />
    )
    return (
      <Widget title="Per kategori" action={toggle}>
        {measure === 'count' ? <BarList limit={limit} data={countRows} /> : <BarList limit={limit} unit="" format={formatMoney} emptyText={emptyValue} data={valueRows} />}
      </Widget>
    )
  }
  if (size === 'two_thirds') {
    return (
      <Widget title="Per kategori">
        <div className="grid grid-cols-2 divide-x divide-slate-100">
          <div className="min-w-0">
            <h3 className="px-4 pt-3 text-sm font-medium text-slate-700">Jumlah aset</h3>
            <BarList limit={6} data={countRows} />
          </div>
          <div className="min-w-0">
            <h3 className="px-4 pt-3 text-sm font-medium text-slate-700">Nilai perolehan</h3>
            <BarList limit={6} unit="" format={formatMoney} emptyText={emptyValue} data={valueRows} />
          </div>
        </div>
      </Widget>
    )
  }
  const totalCount = sum(data.by_category.map((c) => c.count))
  const values = new Map(data.value_by_category.map((c) => [c.id, c]))
  return (
    <Widget title="Per kategori">
      <div className="max-h-96 overflow-y-auto overscroll-contain px-4 pb-2">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-white">
            <tr className="text-left text-xs text-slate-500">
              <th className="py-2 font-medium">Kategori</th>
              <th className="w-20 py-2 text-right font-medium">Jumlah</th>
              <th className="w-1/4 py-2 pl-4 font-medium">Porsi</th>
              <th className="py-2 text-right font-medium">Nilai perolehan</th>
              <th className="py-2 text-right font-medium">Rata-rata nilai</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data.by_category.map((c) => {
              const value = values.get(c.id)
              const share = totalCount > 0 ? (c.count / totalCount) * 100 : 0
              return (
                <tr key={c.id}>
                  <td className="py-1.5">
                    <Link to={`/aset?category_ids=${c.id}`} className="text-slate-700 hover:text-brand-700 hover:underline">
                      {c.name}
                    </Link>
                  </td>
                  <td className="py-1.5 text-right tabular-nums text-slate-900">{formatCount(c.count)}</td>
                  <td className="py-1.5 pl-4">
                    <div className="flex items-center gap-2">
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                        <div className="h-full rounded-full" style={{ width: `${share}%`, backgroundColor: CATEGORICAL[0] }} />
                      </div>
                      <span className="w-11 text-right text-xs tabular-nums text-slate-500">{formatPercent(share)}</span>
                    </div>
                  </td>
                  <td className="py-1.5 text-right tabular-nums text-slate-900">{value ? formatMoney(value.value) : '—'}</td>
                  <td className="py-1.5 text-right tabular-nums text-slate-900">{value && value.count > 0 ? formatMoney(Number(value.value) / value.count) : '—'}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Widget>
  )
}

function LocationWidget({ locations }: { locations: Dashboard['by_location'] }) {
  const size = useWidgetSize()
  const rows = locations.map((l) => ({ key: l.id ?? 'none', label: l.name, value: l.count, to: l.id ? `/aset?location_id=${l.id}` : undefined }))

  if (size !== 'full') {
    return (
      <Widget title="Per lokasi">
        <BarList limit={size === 'third' ? 5 : size === 'half' ? 8 : 12} columns={size === 'two_thirds' ? 2 : 1} data={rows} />
      </Widget>
    )
  }
  const total = sum(rows.map((r) => r.value))
  return (
    <Widget title="Per lokasi">
      {total === 0 ? (
        <p className="p-4 text-sm text-slate-500">Belum ada data.</p>
      ) : (
        <ul className="grid max-h-96 grid-cols-4 gap-3 overflow-y-auto overscroll-contain p-4">
          {rows.map((r) => (
            <li key={r.key} className="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5">
              {r.to ? (
                <Link to={r.to} className="block truncate text-sm text-slate-700 hover:text-brand-700 hover:underline" title={r.label}>
                  {r.label}
                </Link>
              ) : (
                <p className="truncate text-sm text-slate-700">{r.label}</p>
              )}
              <p className="mt-0.5 flex items-baseline justify-between gap-2">
                <span className="text-xl font-semibold text-slate-900">{formatCount(r.value)}</span>
                <span className="text-xs text-slate-500">{formatPercent((r.value / total) * 100)}</span>
              </p>
            </li>
          ))}
        </ul>
      )}
    </Widget>
  )
}

function TransactionTypesWidget({ transactions }: { transactions: Dashboard['transactions'] }) {
  const size = useWidgetSize()
  const title = `Transaksi per jenis (${transactions.days} hari)`
  const emptyText = 'Belum ada transaksi dalam periode ini.'
  const rows = transactions.by_type
    .filter((t) => t.count > 0)
    .sort((a, b) => b.count - a.count)
    .map((t) => ({ key: t.type, label: t.label, value: t.count, to: `/transaksi?type=${t.type}` }))

  if (size === 'third' || size === 'half') {
    return (
      <Widget title={title}>
        <BarList limit={size === 'third' ? 4 : 8} unit="transaksi" emptyText={emptyText} data={rows} />
      </Widget>
    )
  }
  if (size === 'two_thirds') {
    return (
      <Widget title={title}>
        <div className="grid grid-cols-[10rem_minmax(0,1fr)] items-start">
          <div className="p-4 pr-0">
            <Kpi label="Total transaksi" value={formatCount(transactions.total)} note={`${transactions.days} hari terakhir`} />
          </div>
          <BarList columns={2} unit="transaksi" emptyText={emptyText} data={rows} />
        </div>
      </Widget>
    )
  }
  return (
    <Widget title={title}>
      <div className="grid grid-cols-2 divide-x divide-slate-100">
        <div className="min-w-0">
          <BarList unit="transaksi" emptyText={emptyText} data={rows} />
        </div>
        <div className="max-h-80 min-w-0 overflow-y-auto overscroll-contain">
          <h3 className="px-5 pt-3 text-sm font-medium text-slate-700">Transaksi terbaru</h3>
          <RecentList recent={transactions.recent} />
        </div>
      </div>
    </Widget>
  )
}

type ActivityTab = 'attention' | 'idle' | 'recent'

/** The three asset lists: tabs that share a fixed height while narrow, side by side at full width. */
function ActivityWidget({ data }: { data: Dashboard }) {
  const size = useWidgetSize()
  const attentionTotal = sum(Object.values(data.attention.counts))
  const [tab, setTab] = useState<ActivityTab>(attentionTotal > 0 ? 'attention' : 'recent')
  const dense = size === 'third'
  const short = size === 'third' || size === 'half'
  const tabs: { key: ActivityTab; label: string; count?: number }[] = [
    { key: 'attention', label: short ? 'Tindak lanjut' : 'Perlu tindak lanjut', count: attentionTotal },
    { key: 'idle', label: short ? 'Menganggur' : `Aset menganggur (≥ ${data.idle.days} hari)`, count: data.idle.count },
    { key: 'recent', label: short ? 'Terbaru' : 'Transaksi terbaru' },
  ]
  const panel = (key: ActivityTab) => (key === 'attention' ? <AttentionList attention={data.attention} dense={dense} /> : key === 'idle' ? <IdleList idle={data.idle} dense={dense} /> : <RecentList recent={data.transactions.recent} dense={dense} />)

  if (size === 'full') {
    return (
      <Card className="h-full">
        <div className="grid grid-cols-3 divide-x divide-slate-100">
          {tabs.map((t) => (
            <section key={t.key} className="min-w-0" aria-labelledby={`activity-${t.key}`}>
              <h3 id={`activity-${t.key}`} className="flex items-center gap-1.5 border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">
                {t.label}
                <CountBadge count={t.count} />
              </h3>
              <div className="max-h-80 overflow-y-auto overscroll-contain">
                <ModuleBoundary name={t.label} compact>
                  {panel(t.key)}
                </ModuleBoundary>
              </div>
            </section>
          ))}
        </div>
      </Card>
    )
  }

  return (
    <Card className="h-full">
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
              <CountBadge count={t.count} />
            </button>
          ))}
        </div>
        {tab === 'recent' && !short && (
          <Link to="/transaksi" className="px-2 text-sm font-medium text-brand-700 hover:underline">
            Lihat semua
          </Link>
        )}
      </div>
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="max-h-80 overflow-y-auto overscroll-contain">
        <ModuleBoundary name={tabs.find((t) => t.key === tab)!.label} compact>
          {panel(tab)}
        </ModuleBoundary>
      </div>
    </Card>
  )
}

function CountBadge({ count }: { count?: number }) {
  if (count === undefined) return null
  return <span className={clsx('rounded-full px-1.5 text-xs tabular-nums', count > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600')}>{count}</span>
}

function RecentList({ recent, dense = false }: { recent: Dashboard['transactions']['recent']; dense?: boolean }) {
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
              {!dense && t.created_by && ` · ${t.created_by}`}
            </p>
          </div>
          {!dense && <span className="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600">{t.transaction_number}</span>}
        </li>
      ))}
    </ul>
  )
}

function AttentionList({ attention, dense = false }: { attention: Dashboard['attention']; dense?: boolean }) {
  const reasons = Object.keys(attentionLabel) as AttentionReason[]
  return (
    <>
      {!dense && (
        <ul className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3 text-xs">
          {reasons.map((r) => (
            <li key={r} className="rounded-md bg-slate-100 px-2 py-1 text-slate-700">
              {attentionLabel[r]}: <span className="font-semibold tabular-nums text-slate-900">{attention.counts[r]}</span>
            </li>
          ))}
        </ul>
      )}
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
                <p className="text-xs text-slate-500">{dense ? attentionLabel[item.reason] : attentionDetail(item)}</p>
              </div>
              {!dense && <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{attentionLabel[item.reason]}</span>}
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function attentionDetail(item: Dashboard['attention']['items'][number]): ReactNode {
  const parts =
    item.reason === 'overdue_loan'
      ? [item.borrower_name, item.loan_due_date && `jatuh tempo ${formatDate(item.loan_due_date)}`]
      : [item.location]
  return parts.filter(Boolean).join(' · ')
}

function IdleList({ idle, dense = false }: { idle: Dashboard['idle']; dense?: boolean }) {
  if (idle.count === 0) return <p className="p-4 text-sm text-slate-500">Tidak ada aset tersedia yang menganggur lebih dari {idle.days} hari.</p>
  return (
    <>
      {!dense && (
        <p className="border-b border-slate-100 px-5 py-3 text-sm text-slate-600">
          <span className="font-semibold tabular-nums text-slate-900">{formatCount(idle.count)}</span> aset berstatus Tersedia tanpa transaksi apa pun. Pertimbangkan untuk dialokasikan ulang atau dihapuskan.
        </p>
      )}
      <ul className="divide-y divide-slate-100">
        {idle.items.map((item) => (
          <li key={item.asset.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm transition-colors hover:bg-slate-50">
            <div className="min-w-0">
              <Link to={`/aset/${item.asset.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                {item.asset.name}
              </Link>
              {!dense && (
                <p className="text-xs text-slate-500">
                  {[item.location, `aktivitas terakhir ${formatDate(item.last_activity)}`].filter(Boolean).join(' · ')}
                </p>
              )}
            </div>
            <span className="shrink-0 text-xs font-medium tabular-nums text-slate-600">{formatCount(item.idle_days)} hari</span>
          </li>
        ))}
      </ul>
    </>
  )
}

function TotalStat({ data }: { data: Dashboard }) {
  const top = [...statusSlices(data)].sort((a, b) => b.value - a.value).filter((s) => s.value > 0).slice(0, 3)
  return (
    <StatTile
      icon={Boxes}
      tone="brand"
      label="Total aset"
      value={formatCount(data.summary.total)}
      note={`Semua status${data.summary.archived ? ` · ${formatCount(data.summary.archived)} diarsipkan tidak dihitung` : ''}`}
      secondary={`${formatCount(data.utilization.in_service)} digunakan/dipinjam · ${formatCount(data.utilization.available)} tersedia`}
      chart={top.length > 0 && <StackedBar data={top} />}
      to="/aset"
    />
  )
}

function ValueStat({ data }: { data: Dashboard }) {
  const categories = data.value_by_category.slice(0, 3)
  return (
    <StatTile
      icon={Wallet}
      tone="emerald"
      label="Total nilai perolehan"
      value={formatMoney(data.summary.total_value)}
      note="Aset yang nilainya diisi (tanpa yang diarsipkan)"
      secondary={categories[0] && `Terbesar: ${categories[0].name}`}
      chart={categories.length > 0 && <StackedBar format={(value) => formatPercent((value / Number(data.summary.total_value)) * 100)} data={categories.map((c, i) => ({ key: c.id, label: c.name, value: Number(c.value), color: CATEGORICAL[i] }))} />}
    />
  )
}

function AddedStat({ data }: { data: Dashboard }) {
  const previous = data.trend.at(-2)
  return (
    <StatTile
      icon={CalendarPlus}
      tone="violet"
      label="Ditambahkan bulan ini"
      value={formatCount(data.summary.added_this_month)}
      secondary={previous && `Bulan lalu: ${formatCount(previous.added)}`}
      chart={data.trend.length > 0 && <MiniColumns unit="aset" label="Aset ditambahkan per bulan, 12 bulan terakhir" data={data.trend.map((t) => ({ key: t.month, label: monthShortLabel(t.month), value: t.added }))} />}
    />
  )
}

function TransactionsStat({ data }: { data: Dashboard }) {
  const busiest = [...data.transactions.by_type].sort((a, b) => b.count - a.count)[0]
  return (
    <StatTile
      icon={History}
      tone="amber"
      label={`Transaksi ${data.transactions.days} hari terakhir`}
      value={formatCount(data.transactions.total)}
      secondary={busiest && busiest.count > 0 ? `Terbanyak: ${busiest.label}` : undefined}
      chart={data.trend.length > 0 && <MiniColumns unit="transaksi" label="Perubahan aset per bulan, 12 bulan terakhir" data={data.trend.map((t) => ({ key: t.month, label: monthShortLabel(t.month), value: t.changes }))} />}
      to="/transaksi"
    />
  )
}

/** Icon chip tints: decorative only — every tile is identified by its text label. */
const tileTones = {
  brand: 'bg-brand-50 text-brand-700 ring-brand-100',
  emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
  violet: 'bg-violet-50 text-violet-700 ring-violet-100',
  amber: 'bg-amber-50 text-amber-700 ring-amber-100',
} as const

/** 1/4 the number; 1/3 adds one more fact; 1/2 adds a small chart beside it. */
function StatTile({ icon: Icon, tone, label, value, note, secondary, chart, to }: { icon: typeof Boxes; tone: keyof typeof tileTones; label: string; value: string; note?: string; secondary?: ReactNode; chart?: ReactNode; to?: string }) {
  const size: WidgetSize = useWidgetSize()
  const body = (
    <div className={clsx('flex h-full items-start gap-4 rounded-xl bg-white p-5 shadow-card ring-1 ring-slate-200/80 transition-shadow duration-200', to && 'group-hover:shadow-raised group-hover:ring-brand-200')}>
      <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl ring-1', tileTones[tone])}>
        <Icon className="size-5" aria-hidden />
      </span>
      <div className="@container min-w-0 flex-1">
        <p className="flex items-center justify-between gap-2 text-sm font-medium text-slate-600">
          {label}
          {to && <ArrowUpRight className="size-4 shrink-0 text-slate-300 transition-colors group-hover:text-brand-600" aria-hidden />}
        </p>
        {/* Long figures (money) shrink with the tile instead of being cut off; short counts keep the full size. */}
        <p className={clsx('mt-1 truncate font-display font-semibold tracking-tight text-slate-900', value.length > 10 ? 'text-[clamp(1rem,9.5cqi,1.5rem)]' : 'text-2xl')} title={value}>
          {value}
        </p>
        {size !== 'quarter' && secondary && <p className="mt-0.5 truncate text-sm text-slate-700">{secondary}</p>}
        {note && <p className="mt-0.5 text-xs text-slate-500">{note}</p>}
      </div>
      {size === 'half' && chart && <div className="w-2/5 shrink-0 self-center">{chart}</div>}
    </div>
  )
  return to ? (
    <Link to={to} className="group block h-full rounded-xl">
      {body}
    </Link>
  ) : (
    body
  )
}
