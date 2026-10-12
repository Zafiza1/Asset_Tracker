import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { GenericDefinition, Paginated, Resource, TenantCategory, TenantDefinition, TenantVersion } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage } from '../../lib/forms'
import { formatDateTime, masterStatusLabel, versionStatusLabel } from '../../lib/format'
import { toOptions, useOptions } from './options'

const columns: Column<TenantDefinition>[] = [
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
  { key: 'generic', header: 'Generic Master', cell: (d) => d.generic_definition?.name ?? '—', hideOnMobile: true },
  { key: 'category', header: 'Kategori', cell: (d) => d.category?.name ?? '—', hideOnMobile: true },
  {
    key: 'version',
    header: 'Versi',
    cell: (d) => (
      <div className="flex flex-wrap gap-1">
        {d.current_version ? <Badge tone="green">v{d.current_version.version_number}</Badge> : <span className="text-slate-500">Belum dirilis</span>}
        {d.draft_version && <Badge tone="blue">Draft v{d.draft_version.version_number}</Badge>}
      </div>
    ),
  },
  { key: 'status', header: 'Status', cell: (d) => <Badge tone={statusTone[d.status]}>{masterStatusLabel[d.status]}</Badge> },
]

export function TenantDefinitionsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [adopting, setAdopting] = useState(false)
  const filters = { page: params.get('page'), search: params.get('search'), status: params.get('status') }
  const query = useQuery({
    queryKey: ['/asset-definitions', filters],
    queryFn: () => api.get<Paginated<TenantDefinition>>(`/asset-definitions${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Definisi Aset"
        description="Master kustom perusahaan: definisi Generic Master yang diadopsi, dengan atribut tambahan dan override yang diizinkan."
        actions={
          can('master.tenant.create') && (
            <Button onClick={() => setAdopting(true)}>
              <Plus className="size-4" aria-hidden /> Adopsi definisi
            </Button>
          )
        }
      />
      <Card>
        <form
          role="search"
          className="flex flex-wrap gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            setParams(search ? { search } : {})
          }}
        >
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari kode atau nama" className="pl-8" aria-label="Cari definisi" />
          </div>
          <Button type="submit" variant="secondary">
            Cari
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Belum ada definisi aset" description="Adopsi definisi dari katalog Generic Master untuk mulai mendaftarkan aset." />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(d) => navigate(`/master/definisi/${d.id}`)} />
            <Pagination
              meta={query.data!.meta}
              onPage={(p) => {
                const next = new URLSearchParams(params)
                next.set('page', String(p))
                setParams(next)
              }}
            />
          </>
        )}
      </Card>
      {adopting && <AdoptModal onClose={() => setAdopting(false)} />}
    </>
  )
}

interface AdoptValues {
  generic_definition_id: string
  code: string
  name: string
  tenant_category_id: string
  description: string
}

/** Adopt a generic definition; preselected when opened from the catalog. */
export function AdoptModal({ onClose, genericDefinition }: { onClose: () => void; genericDefinition?: GenericDefinition }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const generics = useOptions<GenericDefinition>('/generic-masters/definitions').data
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  const { register, handleSubmit, setError, formState, setValue, getValues } = useForm<AdoptValues>({
    defaultValues: { generic_definition_id: genericDefinition?.id ?? '', code: '', name: genericDefinition?.name ?? '', tenant_category_id: '', description: '' },
  })

  const adopt = useMutation({
    mutationFn: (v: AdoptValues) =>
      api.post<Resource<TenantDefinition>>('/asset-definitions', { ...v, tenant_category_id: v.tenant_category_id || null, description: v.description || null }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['/asset-definitions'] })
      navigate(`/master/definisi/${res.data.id}`)
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <Modal
      open
      title="Adopsi definisi Generic Master"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="adopt-form" loading={adopt.isPending}>
            Adopsi
          </Button>
        </>
      }
    >
      <form id="adopt-form" noValidate className="space-y-4" onSubmit={handleSubmit((v) => (setFormError(null), adopt.mutate(v)))}>
        {formError && <Alert tone="error">{formError}</Alert>}
        <p className="text-sm text-slate-600">Versi 1 dibuat sebagai draft yang dipin ke versi rilis terbaru definisi generik. Generic Master tidak pernah diubah.</p>
        <Field label="Definisi Generic Master" required error={formState.errors.generic_definition_id?.message}>
          {(p) => (
            <Select
              {...p}
              {...register('generic_definition_id', {
                required: 'Pilih definisi.',
                onChange: (e) => {
                  const picked = generics?.find((g) => g.id === e.target.value)
                  if (picked && !getValues('name')) setValue('name', picked.name)
                },
              })}
            >
              <option value="">— Pilih —</option>
              {generics?.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name} ({g.code}) — {g.category?.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Kode" required error={formState.errors.code?.message} hint="Unik di perusahaan, tidak dapat diubah.">
            {(p) => <Input {...p} {...register('code', { required: 'Kode wajib diisi.' })} />}
          </Field>
          <Field label="Nama" required error={formState.errors.name?.message}>
            {(p) => <Input {...p} {...register('name', { required: 'Nama wajib diisi.' })} />}
          </Field>
        </div>
        <Field label="Kategori perusahaan" error={formState.errors.tenant_category_id?.message} hint="Harus berinduk pada kategori generik yang mencakup definisi ini.">
          {(p) => (
            <Select {...p} {...register('tenant_category_id')}>
              <option value="">— Tanpa kategori —</option>
              {categories.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Deskripsi">{(p) => <Textarea {...p} rows={2} {...register('description')} />}</Field>
      </form>
    </Modal>
  )
}

export function TenantDefinitionDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [creatingVersion, setCreatingVersion] = useState(false)
  const query = useQuery({ queryKey: ['/asset-definitions', id], queryFn: () => api.get<Resource<TenantDefinition>>(`/asset-definitions/${id}`) })
  const lifecycle = useMutation({
    mutationFn: (action: 'archive' | 'restore') => api.post(`/asset-definitions/${id}/${action}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['/asset-definitions'] }),
  })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const definition = query.data!.data
  const editable = can('master.tenant.update') && definition.status !== 'archived'

  return (
    <>
      <PageHeader
        title={definition.name}
        description={`${definition.code} · diadopsi dari ${definition.generic_definition?.name ?? '—'}`}
        actions={
          <>
            {editable && (
              <Button variant="secondary" onClick={() => setEditing(true)}>
                Ubah
              </Button>
            )}
            {editable && !definition.draft_version && <Button onClick={() => setCreatingVersion(true)}>Buat versi baru</Button>}
            {can('master.tenant.archive') && (
              <Button variant="secondary" loading={lifecycle.isPending} onClick={() => lifecycle.mutate(definition.status === 'archived' ? 'restore' : 'archive')}>
                {definition.status === 'archived' ? 'Pulihkan' : 'Arsipkan'}
              </Button>
            )}
          </>
        }
      />
      {lifecycle.isError && (
        <div className="mb-4">
          <Alert tone="error">{errorMessage(lifecycle.error)}</Alert>
        </div>
      )}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Informasi" className="lg:col-span-1">
          <dl className="space-y-3 p-4 text-sm">
            <Info label="Status">
              <Badge tone={statusTone[definition.status]}>{masterStatusLabel[definition.status]}</Badge>
            </Info>
            <Info label="Kategori">{definition.category?.name ?? '—'}</Info>
            <Info label="Generic Master">
              <Link className="text-brand-700 hover:underline" to={`/master/katalog/${definition.generic_definition_id}`}>
                {definition.generic_definition?.name}
              </Link>
            </Info>
            <Info label="Versi aktif">{definition.current_version ? `v${definition.current_version.version_number}` : 'Belum ada versi rilis'}</Info>
            <Info label="Deskripsi">{definition.description ?? '—'}</Info>
          </dl>
        </Card>
        <Card title="Versi" className="lg:col-span-2">
          <DataTable
            columns={[
              { key: 'v', header: 'Versi', cell: (v: TenantVersion) => <span className="font-medium">v{v.version_number}</span> },
              { key: 'status', header: 'Status', cell: (v: TenantVersion) => <Badge tone={statusTone[v.status]}>{versionStatusLabel[v.status]}</Badge> },
              { key: 'generic', header: 'Generic', cell: (v: TenantVersion) => (v.generic_version ? `v${v.generic_version.version_number}` : '—') },
              { key: 'released', header: 'Dirilis', cell: (v: TenantVersion) => formatDateTime(v.released_at), hideOnMobile: true },
            ]}
            rows={definition.versions ?? []}
            onRowClick={(v) => navigate(`/master/definisi-versi/${v.id}`)}
          />
        </Card>
      </div>
      {editing && <EditDefinitionModal definition={definition} onClose={() => setEditing(false)} />}
      {creatingVersion && <NewVersionModal definition={definition} onClose={() => setCreatingVersion(false)} />}
    </>
  )
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-slate-800">{children}</dd>
    </div>
  )
}

function EditDefinitionModal({ definition, onClose }: { definition: TenantDefinition; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  const { register, handleSubmit, setError, formState } = useForm({
    defaultValues: { name: definition.name, description: definition.description ?? '', tenant_category_id: definition.tenant_category_id ?? '', status: definition.status },
  })
  const save = useMutation({
    mutationFn: (v: { name: string; description: string; tenant_category_id: string; status: string }) =>
      api.patch(`/asset-definitions/${definition.id}`, { ...v, description: v.description || null, tenant_category_id: v.tenant_category_id || null }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['/asset-definitions'] })
      onClose()
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })
  return (
    <Modal
      open
      title="Ubah definisi"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="definition-form" loading={save.isPending}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="definition-form" noValidate className="space-y-4" onSubmit={handleSubmit((v) => (setFormError(null), save.mutate(v)))}>
        {formError && <Alert tone="error">{formError}</Alert>}
        <Field label="Nama" required error={formState.errors.name?.message}>
          {(p) => <Input {...p} {...register('name', { required: 'Nama wajib diisi.' })} />}
        </Field>
        <Field label="Kategori perusahaan" error={formState.errors.tenant_category_id?.message}>
          {(p) => (
            <Select {...p} {...register('tenant_category_id')}>
              <option value="">— Tanpa kategori —</option>
              {categories.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Status" hint="Definisi nonaktif tidak dapat dipakai untuk registrasi aset baru.">
          {(p) => (
            <Select {...p} {...register('status')}>
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </Select>
          )}
        </Field>
        <Field label="Deskripsi">{(p) => <Textarea {...p} rows={2} {...register('description')} />}</Field>
      </form>
    </Modal>
  )
}

/** New draft; by default it keeps the current generic version (an upgrade is an explicit choice). */
function NewVersionModal({ definition, onClose }: { definition: TenantDefinition; onClose: () => void }) {
  const navigate = useNavigate()
  const [genericVersionId, setGenericVersionId] = useState('')
  const generic = useQuery({
    queryKey: ['/generic-masters/definitions', definition.generic_definition_id],
    queryFn: () => api.get<Resource<GenericDefinition>>(`/generic-masters/definitions/${definition.generic_definition_id}`),
  })
  const create = useMutation({
    mutationFn: () => api.post<Resource<TenantVersion>>(`/asset-definitions/${definition.id}/versions`, { generic_version_id: genericVersionId || null }),
    onSuccess: (res) => navigate(`/master/definisi-versi/${res.data.id}`),
  })
  const released = generic.data?.data.versions?.filter((v) => v.status === 'released') ?? []

  return (
    <Modal
      open
      title="Buat versi draft baru"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={create.isPending} onClick={() => create.mutate()}>
            Buat draft
          </Button>
        </>
      }
    >
      {create.isError && <Alert tone="error">{errorMessage(create.error)}</Alert>}
      <p className="text-sm text-slate-600">Atribut tambahan dan override dari versi terakhir disalin ke draft. Versi yang sudah dirilis tidak berubah.</p>
      <Field label="Versi Generic Master" hint="Biarkan default untuk tetap memakai versi generik yang sama; pilih versi yang lebih baru untuk upgrade.">
        {(p) => (
          <Select {...p} value={genericVersionId} onChange={(e) => setGenericVersionId(e.target.value)}>
            <option value="">Sama dengan versi sebelumnya</option>
            {released.map((v) => (
              <option key={v.id} value={v.id}>
                v{v.version_number} — dirilis {formatDateTime(v.released_at)}
              </option>
            ))}
          </Select>
        )}
      </Field>
    </Modal>
  )
}
