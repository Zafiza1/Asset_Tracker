import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { FileSpreadsheet, FileText } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, downloadFile, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, assetStatusTone, formatMoney, type Asset, type AssetStatus } from '../assets/api'
import { CategoryFilter } from '../assets/CategoryFilter'

/** Mirrors App\Domain\Reports\AssetReport::summary(). */
export interface AssetReportSummary {
  total: number
  archived: number
  total_value: string
  by_status: { status: AssetStatus; label: string; count: number }[]
  by_category: { id: string; name: string; count: number; value: string }[]
}

type AssetReport = Paginated<Asset> & { summary: AssetReportSummary }
type ExportFormat = 'xlsx' | 'pdf'

const FILTERS = ['page', 'search', 'category_ids', 'location_id', 'status', 'archived', 'acquired_from', 'acquired_to', 'sort'] as const
const ADVANCED = ['category_ids', 'location_id', 'status', 'archived', 'acquired_from', 'acquired_to'] as const

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

  // The export gets the same filters and order as the screen, without paging.
  const exportReport = async (format: ExportFormat) => {
    setExporting(format)
    setExportError(null)
    try {
      const stamp = new Date().toISOString().slice(0, 10).replaceAll('-', '')
      await downloadFile(`/reports/assets/export${qs({ ...filters, page: undefined, format })}`, `laporan_aset_${stamp}.${format}`)
    } catch (error) {
      setExportError(errorMessage(error))
    } finally {
      setExporting(null)
    }
  }

  const nameOf = (rows: { id: string; name: string }[] | undefined, id: string | null) => rows?.find((r) => r.id === id)?.name ?? '…'
  const archived = params.get('archived')
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...selectedCategories.map((id) => ({ key: `category:${id}`, label: nameOf(categoryRows, id), clear: () => update({ category_ids: selectedCategories.filter((c) => c !== id).join(',') }) })),
    ...(params.get('location_id') ? [{ key: 'location', label: `Lokasi: ${nameOf(locationRows, params.get('location_id'))}`, clear: () => update({ location_id: '' }) }] : []),
    ...(params.get('status') ? [{ key: 'status', label: `Status: ${assetStatusLabel[params.get('status') as AssetStatus] ?? params.get('status')}`, clear: () => update({ status: '' }) }] : []),
    ...(archived ? [{ key: 'archived', label: archived === 'only' ? 'Aset nonaktif' : 'Aktif & nonaktif', clear: () => update({ archived: '' }) }] : []),
    ...(params.get('acquired_from') ? [{ key: 'from', label: `Perolehan dari ${formatDate(params.get('acquired_from')!)}`, clear: () => update({ acquired_from: '' }) }] : []),
    ...(params.get('acquired_to') ? [{ key: 'to', label: `Perolehan sampai ${formatDate(params.get('acquired_to')!)}`, clear: () => update({ acquired_to: '' }) }] : []),
  ]

  const columns: Column<Asset>[] = [
    { key: 'code', header: 'Kode', cell: (a) => <span className="font-mono text-xs">{a.asset_number}</span> },
    {
      key: 'name',
      header: 'Aset',
      cell: (a) => (
        <Link to={`/aset/${a.id}`} className="font-medium text-slate-900 hover:text-brand-700" onClick={(e) => e.stopPropagation()}>
          {a.name}
        </Link>
      ),
    },
    { key: 'serial_number', header: 'No Seri', cell: (a) => (a.serial_number ? <span className="font-mono text-xs">{a.serial_number}</span> : <span className="text-slate-400">—</span>), hideOnMobile: true },
    { key: 'category', header: 'Kategori', cell: (a) => a.category?.name ?? '—', hideOnMobile: true },
    { key: 'location', header: 'Lokasi', cell: (a) => a.location?.name ?? <span className="text-slate-400">Tanpa lokasi</span>, hideOnMobile: true },
    {
      key: 'status',
      header: 'Status',
      cell: (a) => (
        <div className="flex flex-wrap gap-1">
          <Badge tone={assetStatusTone[a.status]}>{assetStatusLabel[a.status]}</Badge>
          {a.is_archived && <Badge tone="slate">Nonaktif</Badge>}
        </div>
      ),
    },
    { key: 'acquisition_date', header: 'Tgl Perolehan', cell: (a) => (a.acquisition_date ? formatDate(a.acquisition_date) : <span className="text-slate-400">—</span>), hideOnMobile: true },
    { key: 'acquisition_cost', header: 'Nilai Perolehan', cell: (a) => <span className="tabular-nums">{formatMoney(a.acquisition_cost)}</span>, hideOnMobile: true },
  ]

  const summary = query.data?.summary
  const hasFilters = chips.length > 0

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
      {summary && <SummaryTiles summary={summary} showArchived={archived !== null} />}
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama, kode, atau no seri"
            label="Cari aset"
            aside={
              <Select value={params.get('sort') ?? ''} onChange={(e) => update({ sort: e.target.value })} className="sm:w-64" aria-label="Urutkan">
                <option value="">Urutkan: kode</option>
                <option value="name">Urutkan: nama (A–Z)</option>
                <option value="-acquisition_date">Urutkan: tanggal perolehan terbaru</option>
                <option value="-acquisition_cost">Urutkan: nilai perolehan tertinggi</option>
                <option value="status">Urutkan: status</option>
              </Select>
            }
          />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update(draft)} onClear={() => advanced.set(advanced.empty)}>
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
            <DateRangeFilter from={draft.acquired_from} to={draft.acquired_to} onChange={(acquired_from, acquired_to) => advanced.set({ acquired_from, acquired_to })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={() => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            title={hasFilters ? 'Tidak ada aset yang cocok' : 'Belum ada aset'}
            description={hasFilters ? 'Ubah kata kunci atau filter laporan.' : 'Laporan akan terisi setelah aset didaftarkan.'}
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(a) => navigate(`/aset/${a.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
    </>
  )
}

function SummaryTiles({ summary, showArchived }: { summary: AssetReportSummary; showArchived: boolean }) {
  return (
    <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Card className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Jumlah aset</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{summary.total.toLocaleString('id-ID')}</p>
        {showArchived && <p className="text-xs text-slate-500">{summary.archived.toLocaleString('id-ID')} nonaktif</p>}
      </Card>
      <Card className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Total nilai perolehan</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{formatMoney(summary.total_value)}</p>
      </Card>
      <Card className="p-4 sm:col-span-2">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Per status</p>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {summary.by_status.map((s) => (
            <Badge key={s.status} tone={s.count > 0 ? assetStatusTone[s.status] : 'slate'}>
              {s.label}: {s.count.toLocaleString('id-ID')}
            </Badge>
          ))}
        </div>
      </Card>
    </div>
  )
}
