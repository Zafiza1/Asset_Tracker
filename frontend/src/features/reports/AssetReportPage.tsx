import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { FileBarChart, FileSpreadsheet, FileText, Info, SearchX } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, downloadFile, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, formatMoney, type Asset, type AssetStatus } from '../assets/api'
import { CategoryFilter } from '../assets/CategoryFilter'
import { ReportCharts, type BarRow } from './ReportCharts'
import { AssetStatusBadge } from '../assets/AssetStatusBadge'

interface Breakdown {
  id: string | null
  name: string
  count: number
  value: string
}

/** Mirrors App\Domain\Reports\AssetReport::summary(). */
export interface AssetReportSummary {
  total: number
  archived: number
  /** Sum of the acquisition cost of the `valued` assets; assets without a cost add nothing. */
  total_value: string
  /** Assets of the selection that have an acquisition cost. */
  valued: number
  by_status: { status: AssetStatus; label: string; count: number }[]
  by_category: (Breakdown & { id: string })[]
  /** Assets without a location are one row with id null. */
  by_location: Breakdown[]
}

type AssetReport = Paginated<Asset> & { summary: AssetReportSummary }
type ExportFormat = 'xlsx' | 'pdf'

const FILTERS = ['page', 'per_page', 'search', 'category_ids', 'location_id', 'status', 'archived', 'missing', 'acquired_from', 'acquired_to', 'cost_min', 'cost_max', 'sort'] as const
const ADVANCED = ['category_ids', 'location_id', 'status', 'archived', 'missing', 'acquired_from', 'acquired_to', 'cost_min', 'cost_max'] as const
/** URL keys that change how the report is shown, not which assets are in it. */
const VIEW_KEYS = new Set<string>(['page', 'per_page', 'sort'])
const DEFAULT_PER_PAGE = 25
/** Mirrors AssetAnalytics::QUALITY_FIELDS (the dashboard's "Kelengkapan data"). */
const MISSING_LABEL: Record<string, string> = {
  location_id: 'Lokasi',
  acquisition_date: 'Tanggal perolehan',
  acquisition_cost: 'Nilai perolehan',
  serial_number: 'No seri',
  image_path: 'Foto aset',
}
/** '' is the server default (registration order, oldest first); the column headers set the same keys. */
const SORT_OPTIONS = ['', 'name', '-name', '-acquisition_date', 'acquisition_date', '-acquisition_cost', 'acquisition_cost', 'status']
const SORT_LABEL: Record<string, string> = {
  '': 'urutan pendaftaran',
  name: 'nama (A–Z)',
  '-name': 'nama (Z–A)',
  '-acquisition_date': 'tanggal perolehan terbaru',
  acquisition_date: 'tanggal perolehan terlama',
  '-acquisition_cost': 'nilai perolehan tertinggi',
  acquisition_cost: 'nilai perolehan terendah',
  status: 'status (A–Z)',
  '-status': 'status (Z–A)',
}

const muted = (text = '—') => <span className="text-slate-400">{text}</span>

/** Asset Reports module: the filtered asset register with totals, exportable as PDF or Excel. */
export function AssetReportPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = usePersistedSearchParams('/reports/assets')
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/reports/assets')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [exporting, setExporting] = useState<ExportFormat | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  const selectedCategories = (params.get('category_ids') ?? '').split(',').filter(Boolean)
  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft

  const categoryRows = useOptions('/tenant-masters/categories').data
  const categories = toOptions(categoryRows)
  const locationRows = useOptions('/locations').data
  const locations = toOptions(locationRows)

  const query = useQuery({
    queryKey: ['/reports/assets', filters],
    queryFn: () => api.get<AssetReport>(`/reports/assets${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) {
        merged.set(k, v)
        persistFilter(k, v)
      } else {
        merged.delete(k)
        unPersistFilter(k)
      }
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }

  // A reversed cost range is applied the right way round instead of failing validation.
  const applyAdvanced = () => {
    const { cost_min, cost_max } = draft
    const reversed = cost_min !== '' && cost_max !== '' && Number(cost_min) > Number(cost_max)
    update(reversed ? { ...draft, cost_min: cost_max, cost_max: cost_min } : draft)
  }

  // The export gets the same filters and order as the screen, without paging.
  const exportReport = async (format: ExportFormat) => {
    setExporting(format)
    setExportError(null)
    try {
      const stamp = new Date().toISOString().slice(0, 10).replaceAll('-', '')
      await downloadFile(`/reports/assets/export${qs({ ...filters, page: undefined, per_page: undefined, format })}`, `laporan_aset_${stamp}.${format}`)
    } catch (error) {
      setExportError(errorMessage(error))
    } finally {
      setExporting(null)
    }
  }

  const nameOf = (rows: { id: string; name: string }[] | undefined, id: string | null) => rows?.find((r) => r.id === id)?.name ?? '…'
  const archived = params.get('archived')
  const status = params.get('status')
  const missing = params.get('missing')
  const [from, to] = [params.get('acquired_from'), params.get('acquired_to')]
  const [costMin, costMax] = [params.get('cost_min'), params.get('cost_max')]
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...selectedCategories.map((id) => ({ key: `category:${id}`, label: `Kategori: ${nameOf(categoryRows, id)}`, clear: () => update({ category_ids: selectedCategories.filter((c) => c !== id).join(',') }) })),
    ...(params.get('location_id') ? [{ key: 'location', label: `Lokasi: ${nameOf(locationRows, params.get('location_id'))}`, clear: () => update({ location_id: '' }) }] : []),
    ...(status ? [{ key: 'status', label: `Status: ${assetStatusLabel[status as AssetStatus] ?? status}`, clear: () => update({ status: '' }) }] : []),
    ...(archived ? [{ key: 'archived', label: archived === 'only' ? 'Aset nonaktif' : 'Aktif & nonaktif', clear: () => update({ archived: '' }) }] : []),
    ...(missing ? [{ key: 'missing', label: `Belum diisi: ${MISSING_LABEL[missing] ?? missing}`, clear: () => update({ missing: '' }) }] : []),
    ...(from || to ? [{ key: 'acquired', label: `Perolehan: ${from ? formatDate(from) : '…'} – ${to ? formatDate(to) : '…'}`, clear: () => update({ acquired_from: '', acquired_to: '' }) }] : []),
    ...(costMin || costMax ? [{ key: 'cost', label: `Nilai: ${costMin ? formatMoney(costMin) : '…'} – ${costMax ? formatMoney(costMax) : '…'}`, clear: () => update({ cost_min: '', cost_max: '' }) }] : []),
  ]
  const hasFilters = FILTERS.some((k) => !VIEW_KEYS.has(k) && params.get(k))
  const resetAll = () => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))
  const sort = params.get('sort') ?? ''
  const sortOptions = SORT_OPTIONS.includes(sort) ? SORT_OPTIONS : [...SORT_OPTIONS, sort]

  const assetLink = (a: Asset, children: ReactNode, className: string) => (
    <Link to={`/aset/${a.id}`} className={className} title={`Buka detail ${a.name}`}>
      {children}
    </Link>
  )
  const columns: Column<Asset>[] = [
    {
      key: 'name',
      header: 'Aset',
      sortKey: 'name',
      className: 'whitespace-normal',
      cell: (a) =>
        assetLink(
          a,
          <>
            {/* Long names stop after two lines; the link's tooltip has the full name. */}
            <span className="line-clamp-2 break-words font-medium text-slate-900 group-hover:text-brand-700 group-hover:underline">{a.name}</span>
            <span className="block truncate text-xs text-slate-500 md:hidden">{[a.category?.name, a.location?.name ?? 'Tanpa lokasi'].filter(Boolean).join(' · ')}</span>
            <span className="block text-xs tabular-nums text-slate-500 md:hidden">{formatMoney(a.acquisition_cost)}</span>
          </>,
          'group block min-w-0',
        ),
    },
    { key: 'serial_number', header: 'No Seri', className: 'w-32', title: (a) => a.serial_number, cell: (a) => (a.serial_number ? <span className="font-mono text-xs">{a.serial_number}</span> : muted()), hideOnMobile: true },
    { key: 'category', header: 'Kategori', className: 'w-36', title: (a) => a.category?.name, cell: (a) => a.category?.name ?? muted(), hideOnMobile: true },
    { key: 'location', header: 'Lokasi', className: 'w-36', title: (a) => a.location?.name ?? 'Tanpa lokasi', cell: (a) => a.location?.name ?? muted('Tanpa lokasi'), hideOnMobile: true },
    {
      key: 'status',
      header: 'Status',
      className: 'w-32 md:w-36',
      sortKey: 'status',
      cell: (a) => (
        <div className="flex flex-wrap gap-1">
          <AssetStatusBadge status={a.status} />
          {a.is_archived && <Badge tone="slate">Nonaktif</Badge>}
        </div>
      ),
    },
    { key: 'acquisition_date', header: 'Tgl Perolehan', className: 'w-32', sortKey: 'acquisition_date', cell: (a) => (a.acquisition_date ? <span className="tabular-nums">{formatDate(a.acquisition_date)}</span> : muted()), hideOnMobile: true },
    {
      key: 'acquisition_cost',
      header: 'Nilai Perolehan',
      className: 'w-40 text-right!',
      sortKey: 'acquisition_cost',
      title: (a) => (a.acquisition_cost === null ? 'Nilai perolehan belum diisi; tidak dihitung dalam total' : undefined),
      cell: (a) => (a.acquisition_cost === null ? muted('Belum diisi') : <span className="tabular-nums text-slate-900">{formatMoney(a.acquisition_cost)}</span>),
      hideOnMobile: true,
    },
  ]

  const summary = query.data?.summary
  const rows = query.data?.data ?? []
  const pageValue = rows.reduce((s, a) => s + Number(a.acquisition_cost ?? 0), 0)
  const locationId = params.get('location_id')

  return (
    <>
      <PageHeader
        title="Laporan Aset"
        description="Daftar aset beserta ringkasan jumlah dan nilai perolehan, sesuai filter yang dipilih."
        actions={
          can('report.export') && (
            <>
              <Button variant="secondary" loading={exporting === 'xlsx'} disabled={exporting !== null} onClick={() => exportReport('xlsx')}>
                <FileSpreadsheet className="size-4" aria-hidden /> Export Excel
              </Button>
              <Button variant="secondary" loading={exporting === 'pdf'} disabled={exporting !== null} onClick={() => exportReport('pdf')}>
                <FileText className="size-4" aria-hidden /> Export PDF
              </Button>
            </>
          )
        }
      />
      {exportError && (
        <div className="mb-4">
          <Alert tone="error" title="Ekspor gagal">
            {exportError}
          </Alert>
        </div>
      )}
      {summary && (
        <>
          <SummaryTiles summary={summary} archived={archived} filtered={hasFilters} onShowUnvalued={() => update({ missing: 'acquisition_cost' })} />
          <ReportCharts
            panels={[
              {
                title: 'Per status',
                description: 'Jumlah aset menurut status.',
                rows: summary.by_status.map((s): BarRow => ({
                  key: s.status,
                  label: assetStatusLabel[s.status] ?? s.label,
                  count: s.count,
                  active: status === s.status,
                  onSelect: () => update({ status: status === s.status ? '' : s.status }),
                })),
              },
              {
                title: 'Per kategori',
                description: 'Kategori dengan aset terbanyak.',
                withValue: true,
                rows: summary.by_category.map((c): BarRow => {
                  const active = selectedCategories.length === 1 && selectedCategories[0] === c.id
                  return { key: c.id, label: c.name, count: c.count, value: Number(c.value), active, onSelect: () => update({ category_ids: active ? '' : c.id }) }
                }),
              },
              {
                title: 'Per lokasi',
                description: 'Lokasi dengan aset terbanyak.',
                withValue: true,
                rows: (summary.by_location ?? []).map((l): BarRow => {
                  const active = l.id ? locationId === l.id : missing === 'location_id'
                  const select: Record<string, string> = l.id ? { location_id: active ? '' : l.id } : { missing: active ? '' : 'location_id' }
                  return { key: l.id ?? 'none', label: l.name, count: l.count, value: Number(l.value), active, onSelect: () => update(select) }
                }),
              },
            ]}
          />
        </>
      )}
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama atau no seri"
            label="Cari aset"
            aside={
              <Select value={sort} onChange={(e) => update({ sort: e.target.value })} className="sm:w-64" aria-label="Urutkan">
                {sortOptions.map((v) => (
                  <option key={v} value={v}>
                    Urutkan: {SORT_LABEL[v] ?? v}
                  </option>
                ))}
              </Select>
            }
          />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={applyAdvanced} onClear={() => advanced.set(advanced.empty)}>
            <CategoryFilter options={categories} selected={draft.category_ids.split(',').filter(Boolean)} onChange={(ids) => advanced.set({ category_ids: ids.join(',') })} />
            <Select value={draft.location_id} onChange={(e) => advanced.set({ location_id: e.target.value })} aria-label="Filter lokasi">
              <option value="">Lokasi: semua</option>
              {locations.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select value={draft.status} onChange={(e) => advanced.set({ status: e.target.value })} aria-label="Filter status">
              <option value="">Status: semua</option>
              {(Object.keys(assetStatusLabel) as AssetStatus[]).map((s) => (
                <option key={s} value={s}>
                  {assetStatusLabel[s]}
                </option>
              ))}
            </Select>
            <Select value={draft.archived} onChange={(e) => advanced.set({ archived: e.target.value })} aria-label="Aset aktif atau nonaktif">
              <option value="">Aset aktif</option>
              <option value="only">Aset nonaktif</option>
              <option value="all">Aktif &amp; nonaktif</option>
            </Select>
            <Select value={draft.missing} onChange={(e) => advanced.set({ missing: e.target.value })} aria-label="Filter kelengkapan data">
              <option value="">Kelengkapan data: semua</option>
              {Object.entries(MISSING_LABEL).map(([k, label]) => (
                <option key={k} value={k}>
                  Belum diisi: {label}
                </option>
              ))}
            </Select>
            <DateRangeFilter from={draft.acquired_from} to={draft.acquired_to} onChange={(acquired_from, acquired_to) => advanced.set({ acquired_from, acquired_to })} />
            <CostField label="Nilai min" value={draft.cost_min} onChange={(cost_min) => advanced.set({ cost_min })} />
            <CostField label="Nilai maks" value={draft.cost_max} onChange={(cost_max) => advanced.set({ cost_max })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
          {hasFilters && summary && (
            <p className="text-xs text-slate-500" aria-live="polite">
              <span className="font-semibold text-slate-700">{summary.total.toLocaleString('id-ID')}</span> aset cocok dengan filter.
            </p>
          )}
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={6} label="Memuat laporan…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState
            icon={hasFilters ? SearchX : FileBarChart}
            title={hasFilters ? 'Tidak ada aset yang cocok' : 'Belum ada aset'}
            description={hasFilters ? 'Ubah kata kunci atau filter laporan.' : 'Laporan akan terisi setelah aset didaftarkan.'}
            action={
              hasFilters ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : (
                <ButtonLink to="/aset" variant="secondary">Buka Data Aset</ButtonLink>
              )
            }
          />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows}
              sort={sort}
              onSort={(next) => update({ sort: next })}
              onRowClick={(a) => navigate(`/aset/${a.id}`)}
              rowHref={(a) => `/aset/${a.id}`}
              className="md:min-w-[80rem]"
            />
            {summary && (
              <div className="flex flex-wrap justify-end gap-x-6 gap-y-1 border-t border-slate-200 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500">
                <span>
                  Nilai perolehan halaman ini: <span className="font-semibold tabular-nums text-slate-800">{formatMoney(pageValue)}</span>
                </span>
                <span>
                  Total seluruh hasil ({summary.total.toLocaleString('id-ID')} aset): <span className="font-semibold tabular-nums text-slate-800">{formatMoney(summary.total_value)}</span>
                </span>
              </div>
            )}
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} onPerPage={(n) => update({ per_page: n === DEFAULT_PER_PAGE ? '' : String(n) })} />
          </>
        )}
      </Card>
    </>
  )
}

function CostField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return (
    <label className="flex items-center gap-2 text-sm text-slate-500">
      <span className="w-20 shrink-0">{label}</span>
      <Input type="number" inputMode="numeric" min={0} step={1} value={value} placeholder="Rp" onChange={(e) => onChange(e.target.value)} />
    </label>
  )
}

function Tile({ label, value, note, hint, action }: { label: string; value: string; note: ReactNode; hint?: string; action?: ReactNode }) {
  return (
    <Card className="flex flex-col p-4">
      <p className="flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
        {label}
        {hint && (
          <span title={hint} aria-label={hint} role="img" className="cursor-help text-slate-400">
            <Info className="size-3.5" aria-hidden />
          </span>
        )}
      </p>
      <p className="mt-1 truncate text-2xl font-semibold tabular-nums text-slate-900" title={value}>
        {value}
      </p>
      <p className="mt-1 text-xs text-slate-500">{note}</p>
      {action && <div className="mt-auto pt-2">{action}</div>}
    </Card>
  )
}

function SummaryTiles({ summary, archived, filtered, onShowUnvalued }: { summary: AssetReportSummary; archived: string | null; filtered: boolean; onShowUnvalued: () => void }) {
  const n = (v: number) => v.toLocaleString('id-ID')
  const valued = summary.valued ?? summary.total
  const unvalued = summary.total - valued
  const scope = archived === 'only' ? 'aset nonaktif' : archived === 'all' ? 'aset aktif & nonaktif' : 'aset aktif'
  const average = valued > 0 ? Number(summary.total_value) / valued : null

  return (
    <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Tile
        label="Jumlah aset"
        value={n(summary.total)}
        note={
          <>
            Seluruh {scope}
            {filtered ? ' yang sesuai filter' : ''}
            {archived === 'all' && summary.archived > 0 ? `, termasuk ${n(summary.archived)} nonaktif` : ''}.
          </>
        }
      />
      <Tile
        label="Total nilai perolehan"
        value={formatMoney(summary.total_value)}
        hint="Nilai saat aset diperoleh, belum dikurangi penyusutan. Aset tanpa nilai perolehan tidak ikut dijumlahkan."
        note={unvalued > 0 ? `Jumlah nilai perolehan ${n(valued)} dari ${n(summary.total)} aset; ${n(unvalued)} aset tanpa nilai tidak dihitung.` : `Jumlah nilai perolehan seluruh ${n(summary.total)} aset.`}
      />
      <Tile
        label="Rata-rata nilai per aset"
        value={average === null ? '—' : formatMoney(Math.round(average))}
        note={valued > 0 ? `Total nilai dibagi ${n(valued)} aset yang memiliki nilai perolehan.` : 'Belum ada aset dengan nilai perolehan.'}
      />
      <Tile
        label="Tanpa nilai perolehan"
        value={n(unvalued)}
        note={unvalued > 0 ? 'Aset yang nilai perolehannya belum diisi, sehingga tidak dihitung dalam total.' : 'Semua aset sudah memiliki nilai perolehan.'}
        action={
          unvalued > 0 && (
            <Button variant="ghost" size="sm" onClick={onShowUnvalued} className="-ml-2">
              Tampilkan aset ini
            </Button>
          )
        }
      />
    </div>
  )
}
