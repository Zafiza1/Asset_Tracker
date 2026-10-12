import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, assetStatusTone, type Asset, type AssetStatus } from './api'
import { AssetFormModal } from './AssetFormModal'
import { CategoryFilter } from './CategoryFilter'

const FILTERS = ['page', 'search', 'category_ids', 'location_id', 'status', 'condition_id', 'archived', 'sort'] as const

/** Asset Management module: search and filter the asset register, register new assets. */
export function AssetsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  const selectedCategories = (params.get('category_ids') ?? '').split(',').filter(Boolean)

  const categoryRows = useOptions('/tenant-masters/categories').data
  const categories = toOptions(categoryRows).map((o, i) => ({ ...o, name: categoryRows![i].name }))
  const locations = toOptions(useOptions('/locations').data)
  const conditions = toOptions(useOptions('/tenant-masters/conditions').data)

  const query = useQuery({
    queryKey: ['/assets', filters],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }
  const hasFilters = FILTERS.some((k) => k !== 'page' && k !== 'sort' && params.get(k))

  const columns: Column<Asset>[] = [
    {
      key: 'name',
      header: 'Aset',
      cell: (a) => (
        <div>
          <Link to={`/aset/${a.id}`} className="font-medium text-slate-900 hover:text-brand-700" onClick={(e) => e.stopPropagation()}>
            {a.name}
          </Link>
          <p className="font-mono text-xs text-slate-500">
            {a.asset_number}
            {a.serial_number && <span className="font-sans"> · SN {a.serial_number}</span>}
          </p>
        </div>
      ),
    },
    { key: 'category', header: 'Kategori', cell: (a) => a.category?.name ?? '—', hideOnMobile: true },
    { key: 'location', header: 'Lokasi', cell: (a) => a.location?.name ?? <span className="text-slate-400">Tanpa lokasi</span>, hideOnMobile: true },
    { key: 'condition', header: 'Kondisi', cell: (a) => a.condition?.name ?? <span className="text-slate-400">—</span>, hideOnMobile: true },
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
        description="Daftar seluruh aset organisasi. Klik aset untuk melihat detail, mengubah data, atau mencatat transaksi."
        actions={
          can('asset.create') && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden /> Tambah aset
            </Button>
          )
        }
      />
      <Card>
        <div className="space-y-2 border-b border-slate-200 p-3">
          <form
            role="search"
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              update({ search })
            }}
          >
            <div className="relative min-w-48 flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nama, nomor aset, atau nomor seri" className="pl-8" aria-label="Cari aset" />
            </div>
            <Button type="submit" variant="secondary">
              Cari
            </Button>
          </form>
          <div className="flex flex-wrap items-start gap-2">
            <CategoryFilter options={categories} selected={selectedCategories} onChange={(ids) => update({ category_ids: ids.join(',') })} />
            <Select value={params.get('location_id') ?? ''} onChange={(e) => update({ location_id: e.target.value })} className="w-auto" aria-label="Filter lokasi">
              <option value="">Lokasi: semua</option>
              {locations.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value })} className="w-auto" aria-label="Filter status">
              <option value="">Status: semua</option>
              {(Object.keys(assetStatusLabel) as AssetStatus[]).map((s) => (
                <option key={s} value={s}>
                  {assetStatusLabel[s]}
                </option>
              ))}
            </Select>
            <Select value={params.get('condition_id') ?? ''} onChange={(e) => update({ condition_id: e.target.value })} className="w-auto" aria-label="Filter kondisi">
              <option value="">Kondisi: semua</option>
              {conditions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select value={params.get('archived') ?? ''} onChange={(e) => update({ archived: e.target.value })} className="w-auto" aria-label="Aset aktif atau nonaktif">
              <option value="">Aset aktif</option>
              <option value="only">Aset nonaktif</option>
              <option value="all">Semua aset</option>
            </Select>
            <Select value={params.get('sort') ?? ''} onChange={(e) => update({ sort: e.target.value })} className="w-auto" aria-label="Urutkan">
              <option value="">Terbaru ditambahkan</option>
              <option value="asset_number">Nomor aset</option>
              <option value="name">Nama (A–Z)</option>
              <option value="-acquisition_date">Tanggal perolehan terbaru</option>
            </Select>
            {hasFilters && (
              <Button variant="ghost" onClick={() => (setSearch(''), setParams(new URLSearchParams()))}>
                Reset filter
              </Button>
            )}
          </div>
        </div>
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
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(a) => navigate(`/aset/${a.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      {creating && <AssetFormModal onClose={() => setCreating(false)} onSaved={(a) => navigate(`/aset/${a.id}`)} />}
    </>
  )
}
