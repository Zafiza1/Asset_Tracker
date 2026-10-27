import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { ActiveFilters, FilterPanel, SearchForm } from '../../components/ui/Filters'
import { searchChip } from '../../components/ui/filterChips'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, ApiError, qs } from '../../lib/api/client'
import type { AttributeDefinition, GenericCategory, GenericDefinition, GenericVersion, Paginated, ReferenceItem, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage } from '../../lib/forms'
import { formatDateTime, masterStatusLabel, versionStatusLabel } from '../../lib/format'
import { AttributeFormModal, AttributeTable } from '../master/attributes'
import { MasterListPage } from '../master/MasterListPage'
import { toOptions, useOptions } from '../master/options'

const BASE = '/platform/generic-masters'
const codeField = { name: 'code', label: 'Kode', required: true, createOnly: true, hint: 'Huruf besar, angka, titik, garis bawah, atau strip.' }
const codeColumn = <T extends { code: string }>() => ({ key: 'code', header: 'Kode', cell: (r: T) => <span className="font-mono text-xs">{r.code}</span> })
const nameColumn = <T extends { name: string }>() => ({ key: 'name', header: 'Nama', cell: (r: T) => <span className="font-medium text-slate-900">{r.name}</span> })

export function PlatformCategoriesPage() {
  const { can } = useAuth()
  const manage = can('master.generic.manage')
  const parents = toOptions(useOptions<GenericCategory>(`${BASE}/categories`).data)
  return (
    <MasterListPage<GenericCategory>
      title="Kategori Generik"
      description="Kategori standar platform. Kategori perusahaan selalu berinduk pada salah satu kategori ini."
      endpoint={`${BASE}/categories`}
      canCreate={manage}
      canUpdate={manage}
      canArchive={manage}
      columns={[
        { key: 'name', header: 'Nama', cell: (r) => <span style={{ paddingLeft: `${r.depth * 1.25}rem` }} className="font-medium text-slate-900">{r.name}</span> },
        codeColumn<GenericCategory>(),
        { key: 'definitions', header: 'Definisi', cell: (r) => r.definitions_count ?? 0 },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'parent_id', label: 'Kategori induk', type: 'select', options: parents, emptyOption: '— Kategori utama —' },
        { name: 'sort_order', label: 'Urutan', type: 'number' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ]}
    />
  )
}

const referenceTabs = {
  units: { label: 'Satuan', endpoint: `${BASE}/units` },
  'location-types': { label: 'Jenis Lokasi', endpoint: `${BASE}/location-types` },
  'transaction-types': { label: 'Jenis Transaksi', endpoint: `${BASE}/transaction-types` },
} as const

/** Units, location types and transaction types (deactivated, never deleted). */
export function PlatformReferencePage() {
  const { can } = useAuth()
  const manage = can('master.generic.manage')
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab') ?? ''
  const tab = (requested in referenceTabs ? requested : 'units') as keyof typeof referenceTabs

  const common = { title: referenceTabs[tab].label, endpoint: referenceTabs[tab].endpoint, canUpdate: manage, canArchive: false, embedded: true }
  const content = {
    units: <MasterListPage<ReferenceItem> key="units" {...common} canCreate={manage} columns={[codeColumn(), nameColumn(), { key: 'symbol', header: 'Simbol', cell: (r) => r.symbol }]} fields={[codeField, { name: 'name', label: 'Nama', required: true }, { name: 'symbol', label: 'Simbol', required: true }, { name: 'quantity', label: 'Besaran' }]} />,
    'location-types': <MasterListPage<ReferenceItem> key="location-types" {...common} canCreate={manage} columns={[codeColumn(), nameColumn()]} fields={[codeField, { name: 'name', label: 'Nama', required: true }, { name: 'description', label: 'Deskripsi', type: 'textarea' }]} />,
    'transaction-types': (
      <MasterListPage<ReferenceItem>
        key="transaction-types"
        {...common}
        description="Kode jenis transaksi ditetapkan oleh sistem; hanya nama dan deskripsi yang dapat diubah."
        canCreate={false}
        columns={[codeColumn(), nameColumn(), { key: 'description', header: 'Deskripsi', cell: (r) => r.description ?? '—', hideOnMobile: true }]}
        fields={[{ name: 'name', label: 'Nama', required: true }, { name: 'description', label: 'Deskripsi', type: 'textarea' }]}
      />
    ),
  }[tab]

  return (
    <>
      <PageHeader title="Referensi Generik" description="Data referensi standar yang dipakai semua organisasi." />
      <div role="tablist" className="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        {Object.entries(referenceTabs).map(([key, t]) => (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={tab === key}
            onClick={() => setParams({ tab: key })}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${tab === key ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
          >
            {t.label}
          </button>
        ))}
      </div>
      {content}
    </>
  )
}

const definitionColumns: Column<GenericDefinition>[] = [
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
  { key: 'category', header: 'Kategori', cell: (d) => d.category?.name ?? '—', hideOnMobile: true },
  { key: 'version', header: 'Versi rilis', cell: (d) => (d.current_version ? `v${d.current_version.version_number}` : 'Belum dirilis') },
  { key: 'status', header: 'Status', cell: (d) => <Badge tone={statusTone[d.status]}>{masterStatusLabel[d.status]}</Badge> },
]

export function PlatformDefinitionsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const filters = { page: params.get('page'), search: params.get('search'), status: params.get('status') }
  const query = useQuery({
    queryKey: [`${BASE}/definitions`, filters],
    queryFn: () => api.get<Paginated<GenericDefinition>>(`${BASE}/definitions${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Definisi Generik"
        description="Definisi aset standar beserta versinya. Versi yang dirilis immutable; perubahan selalu menghasilkan versi baru."
        actions={
          can('master.generic.manage') && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden /> Definisi baru
            </Button>
          )
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm value={search} onChange={setSearch} onSubmit={() => setParams(search ? { search } : {})} placeholder="Cari kode atau nama" label="Cari definisi" />
          <ActiveFilters chips={searchChip(params.get('search'), () => (setSearch(''), setParams({})))} onReset={() => (setSearch(''), setParams({}))} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Belum ada definisi" />
        ) : (
          <>
            <DataTable columns={definitionColumns} rows={query.data!.data} onRowClick={(d) => navigate(`/platform/master/definisi/${d.id}`)} />
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
      {creating && <DefinitionFormModal onClose={() => setCreating(false)} />}
    </>
  )
}

interface DefinitionValues {
  code: string
  name: string
  category_id: string
  description: string
  status: string
}

function DefinitionFormModal({ definition, onClose }: { definition?: GenericDefinition; onClose: () => void }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const categories = toOptions(useOptions<GenericCategory>(`${BASE}/categories`).data)
  const { register, handleSubmit, setError, formState } = useForm<DefinitionValues>({
    defaultValues: {
      code: definition?.code ?? '',
      name: definition?.name ?? '',
      category_id: definition?.category_id ?? '',
      description: definition?.description ?? '',
      status: definition?.status ?? 'active',
    },
  })
  const save = useMutation({
    mutationFn: (v: DefinitionValues) =>
      definition
        ? api.patch<Resource<GenericDefinition>>(`${BASE}/definitions/${definition.id}`, { name: v.name, category_id: v.category_id, description: v.description || null, status: v.status })
        : api.post<Resource<GenericDefinition>>(`${BASE}/definitions`, { code: v.code, name: v.name, category_id: v.category_id, description: v.description || null }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: [`${BASE}/definitions`] })
      if (definition) onClose()
      else navigate(`/platform/master/definisi/${res.data.id}`)
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <Modal
      size="full"
      open
      title={definition ? 'Ubah definisi generik' : 'Definisi generik baru'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="generic-definition-form" loading={save.isPending}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="generic-definition-form" noValidate className="space-y-4" onSubmit={handleSubmit((v) => (setFormError(null), save.mutate(v)))}>
        {formError && <Alert tone="error">{formError}</Alert>}
        {!definition && <p className="text-sm text-slate-600">Definisi dibuat bersama draft versi 1 yang masih kosong.</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Kode" required={!definition} error={formState.errors.code?.message} hint={definition ? 'Tidak dapat diubah.' : 'Contoh: GEN-TRUK'}>
            {(p) => <Input {...p} disabled={!!definition} {...register('code', { required: definition ? false : 'Kode wajib diisi.' })} />}
          </Field>
          <Field label="Nama" required error={formState.errors.name?.message}>
            {(p) => <Input {...p} {...register('name', { required: 'Nama wajib diisi.' })} />}
          </Field>
        </div>
        <Field label="Kategori" required error={formState.errors.category_id?.message}>
          {(p) => (
            <Select {...p} {...register('category_id', { required: 'Pilih kategori.' })}>
              <option value="">— Pilih —</option>
              {categories.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {definition && (
          <Field label="Status" hint="Definisi nonaktif tidak dapat diadopsi organisasi baru.">
            {(p) => (
              <Select {...p} {...register('status')}>
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
              </Select>
            )}
          </Field>
        )}
        <Field label="Deskripsi">{(p) => <Textarea {...p} rows={2} {...register('description')} />}</Field>
      </form>
    </Modal>
  )
}

export function PlatformDefinitionDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const query = useQuery({ queryKey: [`${BASE}/definitions`, id], queryFn: () => api.get<Resource<GenericDefinition>>(`${BASE}/definitions/${id}`) })
  const action = useMutation({
    mutationFn: (a: 'versions' | 'archive' | 'restore') => api.post<Resource<GenericVersion>>(`${BASE}/definitions/${id}/${a}`),
    onSuccess: async (res, a) => {
      await queryClient.invalidateQueries({ queryKey: [`${BASE}/definitions`] })
      if (a === 'versions') navigate(`/platform/master/versi/${res.data.id}`)
    },
  })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const d = query.data!.data
  const manage = can('master.generic.manage')
  const hasDraft = d.versions?.some((v) => v.status === 'draft')

  return (
    <>
      <PageHeader
        title={d.name}
        description={`${d.code} · ${d.category?.name ?? ''}`}
        actions={
          manage && (
            <>
              {d.status !== 'archived' && (
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  Ubah
                </Button>
              )}
              {d.status !== 'archived' && !hasDraft && (
                <Button loading={action.isPending && action.variables === 'versions'} onClick={() => action.mutate('versions')}>
                  Buat draft versi baru
                </Button>
              )}
              <Button variant="secondary" onClick={() => action.mutate(d.status === 'archived' ? 'restore' : 'archive')}>
                {d.status === 'archived' ? 'Pulihkan' : 'Arsipkan'}
              </Button>
            </>
          )
        }
      />
      {action.isError && (
        <div className="mb-4">
          <Alert tone="error">{errorMessage(action.error)}</Alert>
        </div>
      )}
      <Card title="Versi">
        <DataTable
          columns={[
            { key: 'v', header: 'Versi', cell: (v: GenericVersion) => <span className="font-medium">v{v.version_number}</span> },
            { key: 'status', header: 'Status', cell: (v: GenericVersion) => <Badge tone={statusTone[v.status]}>{versionStatusLabel[v.status]}</Badge> },
            { key: 'attributes', header: 'Atribut', cell: (v: GenericVersion) => v.attributes_count ?? '—' },
            { key: 'released', header: 'Dirilis', cell: (v: GenericVersion) => formatDateTime(v.released_at), hideOnMobile: true },
            { key: 'changelog', header: 'Catatan', cell: (v: GenericVersion) => v.changelog ?? '—', hideOnMobile: true },
          ]}
          rows={d.versions ?? []}
          onRowClick={(v) => navigate(`/platform/master/versi/${v.id}`)}
        />
      </Card>
      {editing && <DefinitionFormModal definition={d} onClose={() => setEditing(false)} />}
    </>
  )
}

export function PlatformVersionPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<AttributeDefinition | 'new' | null>(null)
  const [confirm, setConfirm] = useState<'release' | 'deprecate' | 'discard' | null>(null)
  const query = useQuery({ queryKey: [`${BASE}/versions`, id], queryFn: () => api.get<Resource<GenericVersion>>(`${BASE}/versions/${id}`) })
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: [`${BASE}/versions`, id] }), queryClient.invalidateQueries({ queryKey: [`${BASE}/definitions`] })])
  const lifecycle = useMutation({
    mutationFn: (a: 'release' | 'deprecate' | 'discard') => (a === 'discard' ? api.delete(`${BASE}/versions/${id}`) : api.post(`${BASE}/versions/${id}/${a}`)),
    onSuccess: async (_, a) => {
      setConfirm(null)
      if (a === 'discard') navigate(`/platform/master/definisi/${query.data!.data.definition_id}`)
      else await refresh()
    },
  })
  const removeAttribute = useMutation({ mutationFn: (a: AttributeDefinition) => api.delete(`${BASE}/attributes/${a.id}`), onSuccess: refresh })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const v = query.data!.data
  const draft = v.status === 'draft'
  const manage = can('master.generic.manage') && draft
  const fieldErrors = lifecycle.error instanceof ApiError ? Object.values(lifecycle.error.fields).flat() : []

  return (
    <>
      <PageHeader
        title={`${v.definition?.name ?? 'Definisi'} — versi ${v.version_number}`}
        description={draft ? 'Draft: atribut dapat diubah sampai versi dirilis.' : `Dirilis ${formatDateTime(v.released_at)} · immutable.`}
        actions={
          <>
            <Badge tone={statusTone[v.status]}>{versionStatusLabel[v.status]}</Badge>
            <Link to={`/platform/master/definisi/${v.definition_id}`}>
              <Button variant="secondary">Kembali</Button>
            </Link>
            {manage && (
              <Button variant="secondary" onClick={() => (lifecycle.reset(), setConfirm('discard'))}>
                Buang draft
              </Button>
            )}
            {draft && can('master.generic.release') && <Button onClick={() => (lifecycle.reset(), setConfirm('release'))}>Rilis versi</Button>}
            {v.status === 'released' && can('master.generic.release') && (
              <Button variant="secondary" onClick={() => (lifecycle.reset(), setConfirm('deprecate'))}>
                Deprecate
              </Button>
            )}
          </>
        }
      />
      <div className="space-y-4">
        <VersionNotesCard version={v} editable={manage} onSaved={refresh} />
        <Card
          title="Atribut"
          actions={
            manage && (
              <Button size="sm" onClick={() => setEditing('new')}>
                <Plus className="size-4" aria-hidden /> Tambah atribut
              </Button>
            )
          }
        >
          {removeAttribute.isError && (
            <div className="p-3">
              <Alert tone="error">{errorMessage(removeAttribute.error)}</Alert>
            </div>
          )}
          <AttributeTable
            attributes={v.attributes ?? []}
            actions={
              manage
                ? (a) => (
                    <div className="flex justify-end gap-1">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(a)} aria-label={`Ubah ${a.label}`} title="Ubah">
                        <Pencil className="size-4" aria-hidden />
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => removeAttribute.mutate(a)} aria-label={`Hapus ${a.label}`} title="Hapus">
                        <Trash2 className="size-4" aria-hidden />
                      </Button>
                    </div>
                  )
                : undefined
            }
          />
        </Card>
      </div>
      {editing && (
        <AttributeFormModal
          generic
          unitsEndpoint={`${BASE}/units`}
          attribute={editing === 'new' ? undefined : editing}
          onClose={() => setEditing(null)}
          onSubmit={async (payload) => {
            if (editing === 'new') await api.post(`${BASE}/versions/${v.id}/attributes`, payload)
            else await api.patch(`${BASE}/attributes/${editing.id}`, payload)
            await refresh()
          }}
        />
      )}
      <Modal
        open={confirm !== null}
        title={confirm === 'release' ? `Rilis versi ${v.version_number}?` : confirm === 'deprecate' ? 'Deprecate versi ini?' : 'Buang draft?'}
        onClose={() => setConfirm(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirm(null)}>
              Batal
            </Button>
            <Button variant={confirm === 'discard' ? 'danger' : 'primary'} loading={lifecycle.isPending} onClick={() => confirm && lifecycle.mutate(confirm)}>
              Lanjutkan
            </Button>
          </>
        }
      >
        {lifecycle.isError && <Alert tone="error" title={errorMessage(lifecycle.error)}>{fieldErrors.length > 0 && <p>{fieldErrors.join(' ')}</p>}</Alert>}
        <p className="text-sm text-slate-700">
          {confirm === 'release'
            ? 'Versi dan atributnya menjadi immutable. Organisasi tidak terpengaruh sampai mereka meng-upgrade definisinya secara eksplisit.'
            : confirm === 'deprecate'
              ? 'Organisasi tidak dapat mengadopsi atau upgrade ke versi ini lagi. Aset dan versi perusahaan yang sudah memakainya tetap valid.'
              : 'Draft beserta atributnya dihapus. Versi yang dirilis tidak terpengaruh.'}
        </p>
      </Modal>
    </>
  )
}

function VersionNotesCard({ version, editable, onSaved }: { version: GenericVersion; editable: boolean; onSaved: () => Promise<unknown> }) {
  const [changelog, setChangelog] = useState(version.changelog ?? '')
  const [migrationNotes, setMigrationNotes] = useState(version.migration_notes ?? '')
  const save = useMutation({
    mutationFn: () => api.patch(`${BASE}/versions/${version.id}`, { changelog: changelog || null, migration_notes: migrationNotes || null }),
    onSuccess: onSaved,
  })
  return (
    <Card title="Catatan rilis">
      <div className="grid gap-4 p-4 md:grid-cols-2">
        {save.isError && (
          <div className="md:col-span-2">
            <Alert tone="error">{errorMessage(save.error)}</Alert>
          </div>
        )}
        <Field label="Catatan perubahan" hint="Wajib sebelum rilis.">
          {(p) => <Textarea {...p} rows={3} value={changelog} disabled={!editable} onChange={(e) => setChangelog(e.target.value)} />}
        </Field>
        <Field label="Strategi migrasi" hint={version.version_number > 1 ? 'Wajib untuk versi lanjutan: dampak bagi aset yang memakai versi sebelumnya.' : 'Opsional untuk versi 1.'}>
          {(p) => <Textarea {...p} rows={3} value={migrationNotes} disabled={!editable} onChange={(e) => setMigrationNotes(e.target.value)} />}
        </Field>
        {version.schema_hash && <p className="break-all font-mono text-xs text-slate-500 md:col-span-2">Hash skema: {version.schema_hash}</p>}
        {editable && (
          <div className="flex justify-end md:col-span-2">
            <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>
              Simpan catatan
            </Button>
          </div>
        )}
      </div>
    </Card>
  )
}
