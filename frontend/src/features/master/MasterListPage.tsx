import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Pencil, Plus, Power, RotateCcw, Search } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { MasterRecord, Paginated } from '../../lib/api/types'
import { applyApiErrors, errorMessage } from '../../lib/forms'
import { masterStatusLabel } from '../../lib/format'
import type { SelectOption } from './options'

export interface MasterField {
  name: string
  label: string
  type?: 'text' | 'textarea' | 'number' | 'select'
  required?: boolean
  /** Only settable at creation (e.g. codes, which are immutable). */
  createOnly?: boolean
  hint?: string
  options?: SelectOption[]
  /** Label of the empty choice for optional selects. */
  emptyOption?: string
}

export interface MasterFilter {
  name: string
  label: string
  options: SelectOption[]
}

export interface MasterListConfig<T extends MasterRecord> {
  title: string
  description?: string
  /** API path, e.g. "/branches". */
  endpoint: string
  columns: Column<T>[]
  fields: MasterField[]
  canCreate: boolean
  canUpdate: boolean
  /** False hides archive/restore (e.g. reference tables that are only deactivated). */
  canArchive: boolean
  filters?: MasterFilter[]
  headerActions?: ReactNode
  /** Render without page header (inside tabs). */
  embedded?: boolean
  emptyHint?: string
}

type Values = Record<string, string>

/**
 * Search/filter/paginate a master list and create, edit, (de)activate, archive and restore
 * records. Every action is enforced by the API; the flags here only hide what the user
 * cannot do.
 */
export function MasterListPage<T extends MasterRecord>(config: MasterListConfig<T>) {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [editing, setEditing] = useState<T | 'new' | null>(null)
  const [archiving, setArchiving] = useState<T | null>(null)
  const filterValues = Object.fromEntries((config.filters ?? []).map((f) => [f.name, params.get(f.name) ?? '']))
  const listParams = { page: params.get('page'), search: params.get('search'), status: params.get('status'), ...filterValues }

  const query = useQuery({
    queryKey: [config.endpoint, listParams],
    queryFn: () => api.get<Paginated<T>>(`${config.endpoint}${qs(listParams)}`),
    placeholderData: keepPreviousData,
  })

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: [config.endpoint] })
    await queryClient.invalidateQueries({ queryKey: ['options'] })
  }
  const statusChange = useMutation({
    mutationFn: ({ row, action }: { row: T; action: 'activate' | 'deactivate' | 'restore' }) =>
      action === 'restore'
        ? api.post(`${config.endpoint}/${row.id}/restore`)
        : api.patch(`${config.endpoint}/${row.id}`, { status: action === 'activate' ? 'active' : 'inactive' }),
    onSuccess: refresh,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    setParams(merged)
  }

  const actionsColumn: Column<T> = {
    key: 'actions',
    header: 'Aksi',
    className: 'text-right whitespace-nowrap',
    cell: (row) => (
      <div className="flex justify-end gap-1">
        {config.canUpdate && row.status !== 'archived' && (
          <>
            <Button size="sm" variant="ghost" onClick={() => setEditing(row)} aria-label={`Ubah ${row.name}`} title="Ubah">
              <Pencil className="size-4" aria-hidden />
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => statusChange.mutate({ row, action: row.status === 'active' ? 'deactivate' : 'activate' })}
              aria-label={row.status === 'active' ? `Nonaktifkan ${row.name}` : `Aktifkan ${row.name}`}
              title={row.status === 'active' ? 'Nonaktifkan' : 'Aktifkan'}
            >
              <Power className="size-4" aria-hidden />
            </Button>
          </>
        )}
        {config.canArchive && row.status !== 'archived' && (
          <Button size="sm" variant="ghost" onClick={() => setArchiving(row)} aria-label={`Arsipkan ${row.name}`} title="Arsipkan">
            <Archive className="size-4" aria-hidden />
          </Button>
        )}
        {config.canArchive && row.status === 'archived' && (
          <Button size="sm" variant="ghost" onClick={() => statusChange.mutate({ row, action: 'restore' })} aria-label={`Pulihkan ${row.name}`} title="Pulihkan">
            <RotateCcw className="size-4" aria-hidden />
          </Button>
        )}
      </div>
    ),
  }
  const statusColumn: Column<T> = {
    key: 'status',
    header: 'Status',
    cell: (r) => <Badge tone={statusTone[r.status]}>{masterStatusLabel[r.status]}</Badge>,
  }
  const columns = [...config.columns, statusColumn, ...(config.canUpdate || config.canArchive ? [actionsColumn] : [])]

  const body = (
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
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari kode atau nama" className="pl-8" aria-label={`Cari ${config.title}`} />
        </div>
        {config.filters?.map((f) => (
          <Select key={f.name} value={filterValues[f.name]} onChange={(e) => update({ [f.name]: e.target.value, page: '' })} className="w-auto" aria-label={f.label}>
            <option value="">{f.label}: semua</option>
            {f.options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        ))}
        <Select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value, page: '' })} className="w-auto" aria-label="Filter status">
          <option value="">Aktif & nonaktif</option>
          {Object.entries(masterStatusLabel).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
        <Button type="submit" variant="secondary">
          Cari
        </Button>
      </form>
      {statusChange.isError && (
        <div className="p-3">
          <Alert tone="error">{errorMessage(statusChange.error)}</Alert>
        </div>
      )}
      {query.isLoading ? (
        <Spinner />
      ) : query.isError ? (
        <ErrorState error={query.error} />
      ) : query.data!.data.length === 0 ? (
        <EmptyState title="Belum ada data" description={config.emptyHint ?? 'Ubah kata kunci atau filter pencarian.'} />
      ) : (
        <>
          <DataTable columns={columns} rows={query.data!.data} />
          <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
        </>
      )}
    </Card>
  )

  const createButton = config.canCreate && (
    <Button onClick={() => setEditing('new')}>
      <Plus className="size-4" aria-hidden /> Tambah
    </Button>
  )

  return (
    <>
      {config.embedded ? (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          {config.description && <p className="text-sm text-slate-600">{config.description}</p>}
          <div className="ml-auto flex gap-2">
            {config.headerActions}
            {createButton}
          </div>
        </div>
      ) : (
        <PageHeader
          title={config.title}
          description={config.description}
          actions={
            <>
              {config.headerActions}
              {createButton}
            </>
          }
        />
      )}
      {body}
      {editing && <MasterFormModal config={config} record={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={refresh} />}
      {archiving && <ArchiveModal endpoint={config.endpoint} record={archiving} onClose={() => setArchiving(null)} onDone={refresh} />}
    </>
  )
}

function MasterFormModal<T extends MasterRecord>({ config, record, onClose, onSaved }: { config: MasterListConfig<T>; record: T | null; onClose: () => void; onSaved: () => Promise<void> }) {
  const [formError, setFormError] = useState<string | null>(null)
  const defaults: Values = Object.fromEntries(
    config.fields.map((f) => {
      const value = record ? (record as unknown as Record<string, unknown>)[f.name] : undefined
      return [f.name, value === null || value === undefined ? '' : String(value)]
    }),
  )
  const { register, handleSubmit, setError, formState } = useForm<Values>({ defaultValues: defaults })

  const save = useMutation({
    mutationFn: (values: Values) => {
      const payload: Record<string, unknown> = {}
      for (const f of config.fields) {
        if (record && f.createOnly) continue
        const raw = values[f.name]?.trim() ?? ''
        payload[f.name] = raw === '' ? null : f.type === 'number' ? Number(raw) : raw
      }
      return record ? api.patch(`${config.endpoint}/${record.id}`, payload) : api.post(config.endpoint, payload)
    },
    onSuccess: async () => {
      await onSaved()
      onClose()
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <Modal
      open
      title={record ? `Ubah ${config.title}` : `Tambah ${config.title}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="master-form" loading={save.isPending}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="master-form" noValidate className="space-y-4" onSubmit={handleSubmit((v) => (setFormError(null), save.mutate(v)))}>
        {formError && <Alert tone="error">{formError}</Alert>}
        {config.fields.map((f) => {
          const disabled = !!record && !!f.createOnly
          const rules = { required: f.required && !disabled ? `${f.label} wajib diisi.` : false }
          return (
            <Field key={f.name} label={f.label} required={f.required && !disabled} error={formState.errors[f.name]?.message} hint={disabled ? 'Tidak dapat diubah setelah dibuat.' : f.hint}>
              {(p) =>
                f.type === 'select' ? (
                  <Select {...p} disabled={disabled} {...register(f.name, rules)}>
                    <option value="">{f.emptyOption ?? '— Pilih —'}</option>
                    {f.options?.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                ) : f.type === 'textarea' ? (
                  <Textarea {...p} rows={3} disabled={disabled} {...register(f.name, rules)} />
                ) : (
                  <Input {...p} type={f.type === 'number' ? 'number' : 'text'} disabled={disabled} {...register(f.name, rules)} />
                )
              }
            </Field>
          )
        })}
      </form>
    </Modal>
  )
}

function ArchiveModal({ endpoint, record, onClose, onDone }: { endpoint: string; record: MasterRecord; onClose: () => void; onDone: () => Promise<void> }) {
  const [reason, setReason] = useState('')
  const archive = useMutation({
    mutationFn: () => api.post(`${endpoint}/${record.id}/archive`, { reason: reason || null }),
    onSuccess: async () => {
      await onDone()
      onClose()
    },
  })

  return (
    <Modal
      open
      title={`Arsipkan ${record.name}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            Arsipkan
          </Button>
        </>
      }
    >
      {archive.isError && <Alert tone="error">{errorMessage(archive.error)}</Alert>}
      <p className="text-sm text-slate-700">Data arsip tidak dapat dipilih untuk data baru, tetapi riwayatnya tetap tersimpan dan dapat dipulihkan.</p>
      <Field label="Alasan">{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
    </Modal>
  )
}
