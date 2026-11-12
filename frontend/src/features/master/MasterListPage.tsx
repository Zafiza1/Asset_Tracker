import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, FileSpreadsheet, Pencil, Plus, Power, PowerOff, RotateCcw, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { IconButton } from '../../components/ui/IconButton'
import { ImportModal } from '../../components/ui/ImportModal'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column, type RowSelection } from '../../components/ui/Table'
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
  placeholder?: string
  options?: SelectOption[]
  /** Label of the empty choice for optional selects. */
  emptyOption?: string
}

export interface MasterFilter {
  name: string
  label: string
  options: SelectOption[]
}

/** What depends on a record, shown before it is deactivated or archived. */
export interface MasterImpact {
  /** Effects of deactivating, one sentence each. */
  deactivate?: string[]
  /** Why archiving is refused right now (e.g. still in use); the button is disabled while set. */
  archiveBlocked?: string | null
}

/** Excel round trip for editing many records at once (see ImportModal, mode "edit"). */
export interface MasterBulkEdit {
  title: string
  /** GET downloads the sheet, POST uploads it. */
  endpoint: string
  filename: string
  /** Plural noun for messages, e.g. "kategori". */
  noun: string
  /** The API accepts `ids` on the download: rows can be checked to edit only those. */
  selectable?: boolean
}

export interface MasterListConfig<T extends MasterRecord> {
  title: string
  description?: string
  /** API path, e.g. "/departments". */
  endpoint: string
  /** A function receives whether the list is narrowed by search or filters (tree indentation then means little). */
  columns: Column<T>[] | ((context: { filtered: boolean }) => Column<T>[])
  fields: MasterField[]
  canCreate: boolean
  canUpdate: boolean
  /** False hides archive/restore (e.g. reference tables that are only deactivated). */
  canArchive: boolean
  canDelete?: boolean
  filters?: MasterFilter[]
  headerActions?: ReactNode
  /** Render without page header (inside tabs). */
  embedded?: boolean
  emptyHint?: string
  /** Placeholder of the search field. */
  searchPlaceholder?: string
  /** Rows that cannot be changed here (e.g. built-in records): no action buttons. */
  isReadOnly?: (row: T) => boolean
  /** Enable column visibility toggle. */
  showColumnToggle?: boolean
  /** Default columns to show (used when column toggle is enabled). Status and actions are always shown. */
  defaultColumns?: string[]
  /** External editing state (for custom create buttons). */
  editing?: T | 'new' | null
  /** Callback when editing state changes. */
  onEditChange?: (value: T | 'new' | null) => void
  impact?: (row: T) => MasterImpact
  bulkEdit?: MasterBulkEdit
}

type Values = Record<string, string>

/** Columns that are never hidden by the column picker: without them a row cannot be judged or acted on. */
const FIXED_COLUMNS = ['status', 'actions']
const DEFAULT_PER_PAGE = 10
/** Typing pause after which the search applies by itself (Enter / "Cari" still apply at once). */
const SEARCH_DELAY_MS = 400

/**
 * Search/filter/paginate a master list and create, edit, (de)activate, archive and restore
 * records. Every action is enforced by the API; the flags here only hide what the user
 * cannot do.
 */
export function MasterListPage<T extends MasterRecord>(config: MasterListConfig<T>) {
  const queryClient = useQueryClient()
  const [params, setParams] = usePersistedSearchParams(config.endpoint)
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [internalEditing, setInternalEditing] = useState<T | 'new' | null>(null)
  const editing = config.editing !== undefined ? config.editing : internalEditing
  const setEditing = config.onEditChange ?? setInternalEditing
  const [archiving, setArchiving] = useState<T | null>(null)
  const [deactivating, setDeactivating] = useState<T | null>(null)
  const [deleting, setDeleting] = useState<T | null>(null)
  const [bulkEditing, setBulkEditing] = useState(false)
  // Checked rows, kept across pages so a selection can span several pages.
  const [selected, setSelected] = useState<Map<string, T>>(new Map())

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters(config.endpoint)

  const filterValues = Object.fromEntries((config.filters ?? []).map((f) => [f.name, params.get(f.name) ?? '']))
  const advanced = useFilterDraft(params, [...(config.filters ?? []).map((f) => f.name), 'status'])
  const draft = advanced.draft
  const listParams = { page: params.get('page'), per_page: params.get('per_page'), search: params.get('search'), status: params.get('status'), ...filterValues }
  const status = params.get('status')
  const filtered = !!params.get('search') || !!status || Object.values(filterValues).some(Boolean)

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
    setParams(merged)
  }

  // Search as you type, once typing pauses; the applied value also follows the URL (chip removed, reset).
  const applied = params.get('search') ?? ''
  const lastApplied = useRef(applied)
  useEffect(() => {
    if (applied !== lastApplied.current) {
      lastApplied.current = applied
      setSearch(applied)
    }
  }, [applied])
  useEffect(() => {
    const term = search.trim()
    if (term === applied) return
    const timer = setTimeout(() => {
      lastApplied.current = term
      update({ search: term, page: '' })
    }, SEARCH_DELAY_MS)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- update() is rebuilt every render; only the typed text matters
  }, [search])
  const submitSearch = () => {
    lastApplied.current = search.trim()
    update({ search: search.trim(), page: '' })
  }

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
    mutationFn: ({ row, action }: { row: T; action: 'activate' | 'restore' }) =>
      action === 'restore' ? api.post(`${config.endpoint}/${row.id}/restore`) : api.patch(`${config.endpoint}/${row.id}`, { status: 'active' }),
    onSuccess: refresh,
  })
  const busyRow = statusChange.isPending ? statusChange.variables?.row.id : undefined

  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '', page: '' }))),
    ...(config.filters ?? [])
      .filter((f) => filterValues[f.name])
      .map((f) => ({
        key: f.name,
        label: `${f.label}: ${f.options.find((o) => o.value === filterValues[f.name])?.label.trim() ?? '…'}`,
        clear: () => update({ [f.name]: '', page: '' }),
      })),
    ...(status ? [{ key: 'status', label: `Status: ${masterStatusLabel[status as keyof typeof masterStatusLabel] ?? status}`, clear: () => update({ status: '', page: '' }) }] : []),
  ]

  const actionsColumn: Column<T> = {
    key: 'actions',
    header: 'Aksi',
    className: 'w-40 text-right! whitespace-nowrap',
    cell: (row) =>
      config.isReadOnly?.(row) ? null : (
        <div className="flex justify-end gap-0.5">
          {config.canUpdate && row.status !== 'archived' && (
            <>
              <IconButton label={`Ubah ${row.name}`} tooltip="Ubah" onClick={() => setEditing(row)}>
                <Pencil className="size-4" aria-hidden />
              </IconButton>
              {row.status === 'active' ? (
                <IconButton label={`Nonaktifkan ${row.name}`} tooltip="Nonaktifkan" className="hover:text-amber-700" onClick={() => setDeactivating(row)}>
                  <PowerOff className="size-4" aria-hidden />
                </IconButton>
              ) : (
                <IconButton
                  label={`Aktifkan ${row.name}`}
                  tooltip="Aktifkan"
                  className="text-emerald-700 hover:text-emerald-800"
                  loading={busyRow === row.id}
                  onClick={() => statusChange.mutate({ row, action: 'activate' })}
                >
                  {busyRow !== row.id && <Power className="size-4" aria-hidden />}
                </IconButton>
              )}
            </>
          )}
          {config.canArchive && row.status !== 'archived' && (
            <IconButton label={`Arsipkan ${row.name}`} tooltip="Arsipkan" onClick={() => setArchiving(row)}>
              <Archive className="size-4" aria-hidden />
            </IconButton>
          )}
          {config.canArchive && row.status === 'archived' && (
            <IconButton label={`Pulihkan ${row.name}`} tooltip="Pulihkan" loading={busyRow === row.id} onClick={() => statusChange.mutate({ row, action: 'restore' })}>
              {busyRow !== row.id && <RotateCcw className="size-4" aria-hidden />}
            </IconButton>
          )}
          {config.canDelete && row.status === 'archived' && (
            <IconButton label={`Hapus permanen ${row.name}`} tooltip="Hapus permanen" className="hover:text-red-700" onClick={() => setDeleting(row)}>
              <Trash2 className="size-4" aria-hidden />
            </IconButton>
          )}
        </div>
      ),
  }
  const statusColumn: Column<T> = {
    key: 'status',
    header: 'Status',
    className: 'w-28',
    cell: (r) => <Badge tone={statusTone[r.status]}>{masterStatusLabel[r.status]}</Badge>,
  }
  const ownColumns = typeof config.columns === 'function' ? config.columns({ filtered }) : config.columns
  const hasActions = config.canUpdate || config.canArchive || !!config.canDelete
  const columns = [...ownColumns, statusColumn, ...(hasActions ? [actionsColumn] : [])]

  const defaultColumns = config.defaultColumns ?? ownColumns.map((c) => c.key)
  const chosenColumns = config.showColumnToggle ? (params.get('columns') ?? defaultColumns.join(',')).split(',').filter(Boolean) : undefined
  const visibleColumns = chosenColumns ? [...chosenColumns, ...FIXED_COLUMNS] : undefined
  const toggleColumn = (key: string) => {
    const next = chosenColumns!.includes(key) ? chosenColumns!.filter((c) => c !== key) : [...chosenColumns!, key]
    update({ columns: next.join(',') })
  }

  // Multiple Edit takes the checked rows when there are any; the sheet never holds archived records.
  const selectable = !!config.bulkEdit?.selectable && config.canUpdate
  const selectedIds = useMemo(() => new Set(selected.keys()), [selected])
  const selection: RowSelection<T> | undefined = selectable
    ? {
        selected: selectedIds,
        rowLabel: (r) => `${r.code} ${r.name}`,
        onChange: (rows, pick) =>
          setSelected((prev) => {
            const next = new Map(prev)
            for (const r of rows) {
              if (pick) next.set(r.id, r)
              else next.delete(r.id)
            }
            return next
          }),
      }
    : undefined
  const editIds = [...selected.values()].filter((r) => r.status !== 'archived').map((r) => r.id)
  const skippedArchived = selected.size - editIds.length
  const pageIds = new Set(query.data?.data.map((r) => r.id) ?? [])
  const offPage = [...selected.keys()].filter((id) => !pageIds.has(id)).length

  const body = (
    <Card>
      <FilterPanel>
        <SearchForm
          value={search}
          onChange={setSearch}
          onSubmit={submitSearch}
          placeholder={config.searchPlaceholder ?? 'Cari kode atau nama'}
          label={`Cari ${config.title}`}
          aside={
            config.showColumnToggle ? (
              <ColumnToggle
                columns={ownColumns}
                visibleColumns={chosenColumns!}
                onToggle={toggleColumn}
                onReset={() => update({ columns: defaultColumns.join(',') })}
              />
            ) : undefined
          }
        />
        <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update({ ...draft, page: '' })} onClear={() => advanced.set(advanced.empty)}>
          {config.filters?.map((f) => (
            <Select key={f.name} value={draft[f.name]} onChange={(e) => advanced.set({ [f.name]: e.target.value })} aria-label={f.label}>
              <option value="">{f.label}: semua</option>
              {f.options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          ))}
          <Select value={draft.status} onChange={(e) => advanced.set({ status: e.target.value })} aria-label="Filter status">
            <option value="">Status: semua (tanpa arsip)</option>
            {Object.entries(masterStatusLabel).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </AdvancedFilters>
        <ActiveFilters chips={chips} onReset={() => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))} />
      </FilterPanel>
      {statusChange.isError && (
        <div className="p-3">
          <Alert tone="error">{errorMessage(statusChange.error)}</Alert>
        </div>
      )}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-brand-100 bg-brand-50/60 px-4 py-2.5 text-sm" role="status">
          <span className="font-medium text-brand-900">
            {selected.size} {config.bulkEdit!.noun} dipilih
            {offPage > 0 && <span className="font-normal text-brand-800"> ({offPage} di halaman lain)</span>}
          </span>
          {editIds.length > 0 ? (
            <Button size="sm" variant="secondary" onClick={() => setBulkEditing(true)}>
              <FileSpreadsheet className="size-4" aria-hidden /> Multiple Edit yang dipilih
            </Button>
          ) : (
            <span className="text-brand-800">Data arsip tidak dapat diubah lewat Multiple Edit.</span>
          )}
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Map())}>
            Batal pilih
          </Button>
        </div>
      )}
      {query.isLoading ? (
        <Spinner />
      ) : query.isError ? (
        <ErrorState error={query.error} />
      ) : query.data!.data.length === 0 ? (
        <EmptyState title={chips.length > 0 ? 'Tidak ada data yang cocok' : 'Belum ada data'} description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : config.emptyHint} />
      ) : (
        <>
          <DataTable columns={columns} rows={query.data!.data} visibleColumns={visibleColumns} selection={selection} className="min-w-[36rem]" />
          <Pagination
            meta={query.data!.meta}
            onPage={(p) => update({ page: String(p) })}
            onPerPage={(n) => update({ per_page: n === DEFAULT_PER_PAGE ? '' : String(n), page: '' })}
          />
        </>
      )}
    </Card>
  )

  const createButton = config.canCreate && (
    <Button onClick={() => setEditing('new')}>
      <Plus className="size-4" aria-hidden /> Tambah
    </Button>
  )
  // Edits the checked rows when there are any, otherwise every record that is not archived.
  const bulkEditButton = config.bulkEdit && config.canUpdate && (
    <Button variant="secondary" onClick={() => setBulkEditing(true)}>
      <FileSpreadsheet className="size-4" aria-hidden /> Multiple Edit{editIds.length > 0 ? ` (${editIds.length})` : ''}
    </Button>
  )
  const bulk = config.bulkEdit
  const editingSelection = bulkEditing && editIds.length > 0

  return (
    <>
      {config.embedded ? (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          {config.description && <p className="text-sm text-slate-600">{config.description}</p>}
          <div className="ml-auto flex flex-wrap gap-2">
            {bulkEditButton}
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
              {bulkEditButton}
              {config.headerActions}
              {createButton}
            </>
          }
        />
      )}
      {body}
      {editing && <MasterFormModal config={config} record={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={refresh} />}
      {deactivating && (
        <DeactivateModal endpoint={config.endpoint} record={deactivating} effects={config.impact?.(deactivating).deactivate} onClose={() => setDeactivating(null)} onDone={refresh} />
      )}
      {archiving && (
        <ArchiveModal endpoint={config.endpoint} record={archiving} blocked={config.impact?.(archiving).archiveBlocked} onClose={() => setArchiving(null)} onDone={refresh} />
      )}
      {deleting && <DeleteModal endpoint={config.endpoint} record={deleting} onClose={() => setDeleting(null)} onDone={refresh} />}
      {bulk && bulkEditing && (
        <ImportModal
          open
          mode="edit"
          onClose={() => setBulkEditing(false)}
          onSuccess={() => setSelected(new Map())}
          title={`${bulk.title} (Excel)`}
          endpoint={bulk.endpoint}
          templateEndpoint={editingSelection ? `${bulk.endpoint}${qs({ ids: editIds.join(',') })}` : bulk.endpoint}
          templateFilename={bulk.filename}
          scopeNote={
            editingSelection
              ? `${editIds.length} ${bulk.noun} yang dicentang${skippedArchived > 0 ? `; ${skippedArchived} ${bulk.noun} arsip tidak ikut` : ''}`
              : `semua ${bulk.noun} yang belum diarsipkan`
          }
        />
      )}
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
      size="full"
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
                  <Textarea {...p} rows={3} disabled={disabled} placeholder={f.placeholder} {...register(f.name, rules)} />
                ) : (
                  <Input {...p} type={f.type === 'number' ? 'number' : 'text'} disabled={disabled} placeholder={f.placeholder} {...register(f.name, rules)} />
                )
              }
            </Field>
          )
        })}
      </form>
    </Modal>
  )
}

function DeactivateModal({ endpoint, record, effects, onClose, onDone }: { endpoint: string; record: MasterRecord; effects?: string[]; onClose: () => void; onDone: () => Promise<void> }) {
  const deactivate = useMutation({
    mutationFn: () => api.patch(`${endpoint}/${record.id}`, { status: 'inactive' }),
    onSuccess: async () => {
      await onDone()
      onClose()
    },
  })

  return (
    <Modal
      open
      title={`Nonaktifkan ${record.name}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={deactivate.isPending} onClick={() => deactivate.mutate()}>
            Nonaktifkan
          </Button>
        </>
      }
    >
      {deactivate.isError && <Alert tone="error">{errorMessage(deactivate.error)}</Alert>}
      <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">
        <li>Data nonaktif tidak dapat dipilih untuk data baru.</li>
        {(effects ?? ['Data yang sudah memakainya tidak berubah.']).map((e) => (
          <li key={e}>{e}</li>
        ))}
        <li>Dapat diaktifkan kembali kapan saja.</li>
      </ul>
    </Modal>
  )
}

function ArchiveModal({ endpoint, record, blocked, onClose, onDone }: { endpoint: string; record: MasterRecord; blocked?: string | null; onClose: () => void; onDone: () => Promise<void> }) {
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
            {blocked ? 'Tutup' : 'Batal'}
          </Button>
          <Button variant="danger" loading={archive.isPending} disabled={!!blocked} onClick={() => archive.mutate()}>
            Arsipkan
          </Button>
        </>
      }
    >
      {blocked && (
        <Alert tone="warning" title="Belum dapat diarsipkan">
          {blocked}
        </Alert>
      )}
      {archive.isError && <Alert tone="error">{errorMessage(archive.error)}</Alert>}
      <p className="text-sm text-slate-700">Data arsip tidak dapat dipilih untuk data baru dan disembunyikan dari daftar, tetapi riwayatnya tetap tersimpan dan dapat dipulihkan.</p>
      {!blocked && <Field label="Alasan">{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>}
    </Modal>
  )
}

function DeleteModal({ endpoint, record, onClose, onDone }: { endpoint: string; record: MasterRecord; onClose: () => void; onDone: () => Promise<void> }) {
  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`${endpoint}/${record.id}`),
    onSuccess: async () => {
      await onDone()
      onClose()
    },
  })

  return (
    <Modal
      open
      title={`Hapus permanen ${record.name}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" loading={deleteMutation.isPending} onClick={() => deleteMutation.mutate()}>
            Hapus permanen
          </Button>
        </>
      }
    >
      {deleteMutation.isError && <Alert tone="error">{errorMessage(deleteMutation.error)}</Alert>}
      <p className="text-sm text-slate-700">Tindakan ini tidak dapat dibatalkan. Data akan dihapus permanen dari sistem.</p>
    </Modal>
  )
}
