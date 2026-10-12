import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { GenericCategory, GenericDefinition, GenericVersion, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDateTime, versionStatusLabel } from '../../lib/format'
import { AttributeTable } from './attributes'
import { toOptions, useOptions } from './options'
import { AdoptModal } from './TenantDefinitionPages'

const columns: Column<GenericDefinition>[] = [
  {
    key: 'name',
    header: 'Definisi',
    cell: (d) => (
      <div>
        <p className="font-medium text-slate-900">{d.name}</p>
        <p className="font-mono text-xs text-slate-500">{d.code}</p>
      </div>
    ),
  },
  { key: 'category', header: 'Kategori', cell: (d) => d.category?.name ?? '—' },
  { key: 'version', header: 'Versi terbaru', cell: (d) => (d.current_version ? `v${d.current_version.version_number}` : '—') },
  { key: 'description', header: 'Deskripsi', cell: (d) => d.description ?? '—', hideOnMobile: true },
]

/** Read-only Generic Master catalog for organizations. */
export function GenericCatalogPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const categories = toOptions(useOptions<GenericCategory>('/generic-masters/categories').data)
  const filters = { page: params.get('page'), search: params.get('search'), category_id: params.get('category_id') }
  const query = useQuery({
    queryKey: ['/generic-masters/definitions', filters],
    queryFn: () => api.get<Paginated<GenericDefinition>>(`/generic-masters/definitions${qs(filters)}`),
    placeholderData: keepPreviousData,
  })
  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    setParams(merged)
  }

  return (
    <>
      <PageHeader title="Katalog Generic Master" description="Definisi standar yang dikelola platform. Adopsi definisi untuk menyesuaikannya dengan kebutuhan perusahaan." />
      <Card>
        <form
          role="search"
          className="flex flex-wrap gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            update({ search, page: '' })
          }}
        >
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari kode atau nama" className="pl-8" aria-label="Cari katalog" />
          </div>
          <Select value={filters.category_id ?? ''} onChange={(e) => update({ category_id: e.target.value, page: '' })} className="w-auto" aria-label="Filter kategori">
            <option value="">Semua kategori</option>
            {categories.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Cari
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Tidak ada definisi" />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(d) => navigate(`/master/katalog/${d.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
    </>
  )
}

export function GenericCatalogDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [adopting, setAdopting] = useState(false)
  const [versionId, setVersionId] = useState<string | null>(null)
  const definition = useQuery({ queryKey: ['/generic-masters/definitions', id], queryFn: () => api.get<Resource<GenericDefinition>>(`/generic-masters/definitions/${id}`) })
  const selected = versionId ?? definition.data?.data.current_version?.id ?? definition.data?.data.versions?.[0]?.id
  const version = useQuery({
    queryKey: ['/generic-masters/versions', selected],
    queryFn: () => api.get<Resource<GenericVersion>>(`/generic-masters/versions/${selected}`),
    enabled: !!selected,
  })

  if (definition.isLoading) return <Spinner />
  if (definition.isError) return <ErrorState error={definition.error} />
  const d = definition.data!.data

  return (
    <>
      <PageHeader
        title={d.name}
        description={`${d.code} · ${d.category?.name ?? ''}${d.description ? ` — ${d.description}` : ''}`}
        actions={can('master.tenant.create') && d.status === 'active' && <Button onClick={() => setAdopting(true)}>Adopsi definisi ini</Button>}
      />
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Versi" className="lg:col-span-1">
          <ul className="divide-y divide-slate-100">
            {d.versions?.map((v) => (
              <li key={v.id}>
                <button
                  type="button"
                  onClick={() => setVersionId(v.id)}
                  className={`flex w-full items-center justify-between px-4 py-3 text-left text-sm hover:bg-brand-50/50 ${v.id === selected ? 'bg-brand-50' : ''}`}
                >
                  <span>
                    <span className="font-medium">v{v.version_number}</span>
                    <span className="block text-xs text-slate-500">{formatDateTime(v.released_at)}</span>
                  </span>
                  <Badge tone={statusTone[v.status]}>{versionStatusLabel[v.status]}</Badge>
                </button>
              </li>
            ))}
          </ul>
        </Card>
        <Card title={version.data ? `Atribut v${version.data.data.version_number}` : 'Atribut'} className="lg:col-span-2">
          {version.isLoading ? (
            <Spinner />
          ) : version.isError ? (
            <ErrorState error={version.error} />
          ) : version.data ? (
            <>
              {version.data.data.changelog && <p className="border-b border-slate-200 px-4 py-2 text-sm text-slate-600">Catatan: {version.data.data.changelog}</p>}
              <AttributeTable attributes={version.data.data.attributes ?? []} />
            </>
          ) : null}
        </Card>
      </div>
      {adopting && <AdoptModal genericDefinition={d} onClose={() => setAdopting(false)} />}
    </>
  )
}
