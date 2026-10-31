import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ImageOff, Pencil, Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { ActiveFilters, AdvancedFilters, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { ImportModal } from '../../components/ui/ImportModal'
import { Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, assetStatusTone, type Asset, type AssetStatus } from './api'
import { AssetFormModal } from './AssetFormModal'
import { BulkEditModal } from './BulkEditModal'
import { CategoryFilter } from './CategoryFilter'

const FILTERS = ['page', 'search', 'category_ids', 'location_id', 'status', 'archived', 'sort', 'columns'] as const
const ADVANCED = ['category_ids', 'location_id', 'status', 'archived'] as const
const DEFAULT_COLUMNS = ['code', 'name', 'serial_number', 'category', 'location', 'status']

/** Asset Management module: search and filter the asset register, register new assets. */
export function AssetsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = usePersistedSearchParams('/assets')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const [importing, setImporting] = useState(false)
  const [bulkEditing, setBulkEditing] = useState(false)

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/assets')

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  const selectedCategories = (params.get('category_ids') ?? '').split(',').filter(Boolean)
  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft

  const visibleColumns = (params.get('columns') ?? DEFAULT_COLUMNS.join(',')).split(',').filter(Boolean)

  const toggleColumn = (key: string) => {
    const next = visibleColumns.includes(key)
      ? visibleColumns.filter((c) => c !== key)
      : [...visibleColumns, key]
    update({ columns: next.join(',') })
  }

  const resetColumns = () => {
    update({ columns: DEFAULT_COLUMNS.join(',') })
  }

  const categoryRows = useOptions('/tenant-masters/categories').data
  const categories = toOptions(categoryRows)
  const locationRows = useOptions('/locations').data
  const locations = toOptions(locationRows)

  const query = useQuery({
    queryKey: ['/assets', filters],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs(filters)}`),
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
  const hasFilters = FILTERS.some((k) => k !== 'page' && k !== 'sort' && params.get(k))

  // Every applied filter as a removable chip, so the filter controls stay one tidy row.
  const nameOf = (rows: { id: string; name: string }[] | undefined, id: string | null) => rows?.find((r) => r.id === id)?.name ?? '…'
  const archived = params.get('archived')
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...selectedCategories.map((id) => ({ key: `category:${id}`, label: nameOf(categoryRows, id), clear: () => update({ category_ids: selectedCategories.filter((c) => c !== id).join(',') }) })),
    ...(params.get('location_id') ? [{ key: 'location', label: `Lokasi: ${nameOf(locationRows, params.get('location_id'))}`, clear: () => update({ location_id: '' }) }] : []),
    ...(params.get('status') ? [{ key: 'status', label: `Status: ${assetStatusLabel[params.get('status') as AssetStatus] ?? params.get('status')}`, clear: () => update({ status: '' }) }] : []),
    ...(archived ? [{ key: 'archived', label: archived === 'only' ? 'Aset nonaktif' : 'Aktif & nonaktif', clear: () => update({ archived: '' }) }] : []),
  ]

  const columns: Column<Asset>[] = [
    { key: 'code', header: 'Kode', cell: (a) => <span className="font-mono text-xs">{a.asset_number}</span> },
    {
      key: 'name',
      header: 'Aset',
      cell: (a) => (
        <Link to={`/aset/${a.id}`} className="flex items-center gap-2.5 font-medium text-slate-900 hover:text-brand-700" onClick={(e) => e.stopPropagation()}>
          {a.image_url ? (
            <img src={a.image_url} alt="" loading="lazy" className="size-8 shrink-0 rounded object-cover ring-1 ring-slate-200" />
          ) : (
            <span className="flex size-8 shrink-0 items-center justify-center rounded bg-slate-100 ring-1 ring-slate-200">
              <ImageOff className="size-4 text-slate-400" aria-hidden />
            </span>
          )}
          {a.name}
        </Link>
      ),
    },
    {
      key: 'serial_number',
      header: 'No Seri',
      cell: (a) => (a.serial_number ? <span className="font-mono text-xs">{a.serial_number}</span> : <span className="text-slate-400">—</span>),
      hideOnMobile: true,
    },
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
  ]

  return (
    <>
      <PageHeader
        title="Pendataan Aset"
        actions={
          (can('asset.create') || can('asset.update')) && (
            <div className="flex gap-2">
              {can('asset.update') && (
                <Button variant="secondary" onClick={() => setBulkEditing(true)}>
                  <Pencil className="size-4" aria-hidden /> Edit massal
                </Button>
              )}
              {can('asset.create') && (
                <>
                  <Button variant="secondary" onClick={() => setImporting(true)}>
                    <Upload className="size-4" aria-hidden /> Import
                  </Button>
                  <Button onClick={() => setCreating(true)}>
                    <Plus className="size-4" aria-hidden /> Tambah aset
                  </Button>
                </>
              )}
            </div>
          )
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama, kode, atau no seri"
            label="Cari aset"
            aside={
              <div className="flex gap-2">
                <ColumnToggle
                  columns={columns}
                  visibleColumns={visibleColumns}
                  onToggle={toggleColumn}
                  onReset={resetColumns}
                />
                <Select value={params.get('sort') ?? ''} onChange={(e) => update({ sort: e.target.value })} className="sm:w-64" aria-label="Urutkan">
                  <option value="">Urutkan: terbaru ditambahkan</option>
                  <option value="asset_number">Urutkan: kode</option>
                  <option value="name">Urutkan: nama (A–Z)</option>
                  <option value="-acquisition_date">Urutkan: tanggal perolehan terbaru</option>
                </Select>
              </div>
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
            description={hasFilters ? 'Ubah kata kunci atau filter pencarian.' : 'Tambahkan aset pertama Anda. Pastikan kategori dan lokasi sudah dibuat di Master Data.'}
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} visibleColumns={visibleColumns} onRowClick={(a) => navigate(`/aset/${a.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      {bulkEditing && <BulkEditModal onClose={() => setBulkEditing(false)} />}
      {creating && <AssetFormModal onClose={() => setCreating(false)} onSaved={(a) => navigate(`/aset/${a.id}`)} />}
      {importing && (
        <ImportModal
          open={importing}
          onClose={() => setImporting(false)}
          title="Import Aset"
          endpoint="/assets/import"
          templateEndpoint="/assets/template"
          templateFilename="template_aset.xlsx"
        />
      )}
    </>
  )
}
