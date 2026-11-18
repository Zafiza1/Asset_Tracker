import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Copy, FileCheck2, FilePlus2, FileText, LayoutTemplate, PencilRuler, Play, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { FilterPanel, SearchForm } from '../../components/ui/Filters'
import { Modal } from '../../components/ui/Modal'
import { RowMenu, type RowMenuItem } from '../../components/ui/RowMenu'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { toast } from '../../components/ui/toast'
import { errorMessage } from '../../lib/forms'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { SampleTemplatesModal } from './SampleTemplatesModal'
import { dataSourceLabel, templateStatusLabel, templateStatusTone, templatesApi, type ReportTemplate, type TemplateDataSource, type TemplateStatus } from './api'

const FILTERS = ['page', 'per_page', 'search', 'status', 'data_source', 'sort'] as const
const DEFAULT_PER_PAGE = 15
const SORT_OPTIONS = ['', 'name', '-name', 'code', '-code', 'created_at', '-created_at', 'updated_at', '-updated_at', 'status']

/** Custom Report Designer — template list: create, open the designer, lifecycle actions. */
export function ReportTemplatesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [params, setParams] = usePersistedSearchParams('/laporan/template')
  const { updateFilter, removeFilter } = usePersistedFilters('/laporan/template')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [createOpen, setCreateOpen] = useState(false)
  const [samplesOpen, setSamplesOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<ReportTemplate | null>(null)

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  const query = useQuery({
    queryKey: ['/report-templates', filters],
    queryFn: () => templatesApi.list(filters),
    placeholderData: (prev) => prev,
  })

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['/report-templates'] })

  const lifecycle = useMutation({
    mutationFn: ({ template, action }: { template: ReportTemplate; action: 'duplicate' | 'publish' | 'archive' }) =>
      action === 'duplicate' ? templatesApi.duplicate(template.id) : action === 'publish' ? templatesApi.publish(template.id) : templatesApi.archive(template.id),
    onSuccess: (res, vars) => {
      const messages = { duplicate: 'Template diduplikasi', publish: 'Template diterbitkan', archive: 'Template diarsipkan' } as const
      toast.saved(res, messages[vars.action])
      invalidate()
    },
    onError: (e) => toast.error('Aksi gagal', errorMessage(e)),
  })

  const deleteMutation = useMutation({
    mutationFn: (t: ReportTemplate) => templatesApi.remove(t.id),
    onSuccess: () => {
      setDeleteTarget(null)
      toast.success('Template dihapus')
      invalidate()
    },
    onError: (e) => toast.error('Gagal menghapus', errorMessage(e)),
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) {
        merged.set(k, v)
        updateFilter(k, v)
      } else {
        merged.delete(k)
        removeFilter(k)
      }
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }

  const sort = params.get('sort') ?? ''
  const sortOptions = SORT_OPTIONS.includes(sort) ? SORT_OPTIONS : [...SORT_OPTIONS, sort]
  const rows = query.data?.data ?? []

  const rowActions = (t: ReportTemplate): RowMenuItem[] => {
    const items: RowMenuItem[] = []
    if (t.status === 'published' && can('report.generate')) items.push({ label: 'Jalankan', icon: <Play aria-hidden />, onSelect: () => navigate(`/laporan/template/${t.id}/jalankan`) })
    items.push({ label: 'Detail', onSelect: () => navigate(`/laporan/template/${t.id}`) })
    if (can('report.update')) items.push({ label: 'Buka Desainer', icon: <PencilRuler aria-hidden />, onSelect: () => navigate(`/laporan/template/${t.id}/desain`) })
    if (can('report.create')) items.push({ label: 'Duplikat', icon: <Copy aria-hidden />, onSelect: () => lifecycle.mutate({ template: t, action: 'duplicate' }) })
    if (can('report.publish') && t.status !== 'archived') {
      if (t.draft_version) items.push({ label: 'Terbitkan draft', icon: <FileCheck2 className="size-4" aria-hidden />, onSelect: () => lifecycle.mutate({ template: t, action: 'publish' }) })
      if (t.status !== 'published' || !t.draft_version) items.push({ label: 'Arsipkan', icon: <Archive aria-hidden />, onSelect: () => lifecycle.mutate({ template: t, action: 'archive' }) })
    }
    if (can('report.delete')) items.push({ label: 'Hapus', icon: <Trash2 aria-hidden />, tone: 'danger', onSelect: () => setDeleteTarget(t) })
    return items
  }

  const columns: Column<ReportTemplate>[] = [
    {
      key: 'name',
      header: 'Template',
      sortKey: 'name',
      className: 'whitespace-normal',
      cell: (t) => (
        <div className="min-w-0">
          <Link to={`/laporan/template/${t.id}`} className="font-medium text-slate-900 hover:text-brand-700 hover:underline">
            {t.name}
          </Link>
          <span className="block truncate text-xs text-slate-500">
            {t.code}
            {t.category ? ` · ${t.category}` : ''}
            {t.description ? ` — ${t.description}` : ''}
          </span>
        </div>
      ),
    },
    { key: 'data_source', header: 'Sumber Data', className: 'w-40', cell: (t) => <span className="text-slate-600">{dataSourceLabel[t.data_source]}</span>, hideOnMobile: true },
    { key: 'status', header: 'Status', className: 'w-32', sortKey: 'status', cell: (t) => <Badge tone={templateStatusTone[t.status]}>{templateStatusLabel[t.status]}</Badge> },
    {
      key: 'active_version',
      header: 'Versi',
      className: 'w-24 text-center',
      cell: (t) => (t.active_version ? <span className="tabular-nums">v{t.active_version}</span> : <span className="text-slate-400">—</span>),
      hideOnMobile: true,
    },
    { key: 'created_at', header: 'Dibuat', className: 'w-32', sortKey: 'created_at', cell: (t) => <span className="tabular-nums text-slate-600">{formatDate(t.created_at)}</span>, hideOnMobile: true },
    { key: 'updated_at', header: 'Diperbarui', className: 'w-32', sortKey: 'updated_at', cell: (t) => <span className="tabular-nums text-slate-600">{formatDate(t.updated_at)}</span>, hideOnMobile: true },
    {
      key: 'actions',
      header: '',
      className: 'w-14 text-right',
      cell: (t) => <RowMenu label={`Aksi ${t.name}`} items={rowActions(t)} />,
    },
  ]

  return (
    <>
      <PageHeader
        title="Template Laporan"
        description="Susun sendiri format laporan dan data yang dilaporkan: seret elemen ke kertas di desainer, terbitkan, lalu jalankan untuk mengunduh PDF/Excel."
        actions={
          can('report.create') && (
            <>
              <Button variant="secondary" onClick={() => setSamplesOpen(true)}>
                <LayoutTemplate className="size-4" aria-hidden /> Mulai dari contoh
              </Button>
              <Button onClick={() => setCreateOpen(true)}>
                <FilePlus2 className="size-4" aria-hidden /> Template Baru
              </Button>
            </>
          )
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama, kode, atau deskripsi"
            label="Cari template"
            aside={
              <Select value={sort} onChange={(e) => update({ sort: e.target.value })} className="sm:w-56" aria-label="Urutkan">
                {sortOptions.map((v) => (
                  <option key={v} value={v}>
                    Urutkan: {SORT_LABEL[v] ?? v}
                  </option>
                ))}
              </Select>
            }
          />
          <div className="flex flex-wrap gap-2">
            <Select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value })} className="sm:w-44" aria-label="Filter status">
              <option value="">Status: semua</option>
              {(Object.keys(templateStatusLabel) as TemplateStatus[]).map((s) => (
                <option key={s} value={s}>{templateStatusLabel[s]}</option>
              ))}
            </Select>
            <Select value={params.get('data_source') ?? ''} onChange={(e) => update({ data_source: e.target.value })} className="sm:w-48" aria-label="Filter sumber data">
              <option value="">Sumber data: semua</option>
              {(Object.keys(dataSourceLabel) as TemplateDataSource[]).map((s) => (
                <option key={s} value={s}>{dataSourceLabel[s]}</option>
              ))}
            </Select>
          </div>
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={6} label="Memuat template…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState
            icon={FileText}
            title="Belum ada template laporan"
            description="Buat template pertama untuk mulai mendesain laporan kustom."
            action={can('report.create') ? <Button onClick={() => setCreateOpen(true)}>Template Baru</Button> : undefined}
          />
        ) : (
          <DataTable columns={columns} rows={rows} sort={sort} onSort={(next) => update({ sort: next })} rowHref={(t) => `/laporan/template/${t.id}`} />
        )}
        {query.data?.meta && <Pagination meta={query.data.meta} onPage={(p) => update({ page: String(p) })} onPerPage={(n) => update({ per_page: n === DEFAULT_PER_PAGE ? '' : String(n) })} />}
      </Card>

      <SampleTemplatesModal open={samplesOpen} onClose={() => setSamplesOpen(false)} onCreated={(t) => navigate(can('report.update') ? `/laporan/template/${t.id}/desain` : `/laporan/template/${t.id}`)} />
      <CreateTemplateModal open={createOpen} onClose={() => setCreateOpen(false)} onCreated={(t) => navigate(can('report.update') ? `/laporan/template/${t.id}/desain` : `/laporan/template/${t.id}`)} />

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Hapus Template?"
        confirmLabel="Hapus"
        tone="danger"
        loading={deleteMutation.isPending}
        error={deleteMutation.isError ? deleteMutation.error : undefined}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget)}
        onClose={() => setDeleteTarget(null)}
      >
        Template <span className="font-semibold">{deleteTarget?.name}</span> akan dihapus. Riwayat export yang sudah dibuat tetap tersimpan.
      </ConfirmDialog>
    </>
  )
}

const SORT_LABEL: Record<string, string> = {
  '': 'default',
  name: 'nama (A–Z)',
  '-name': 'nama (Z–A)',
  code: 'kode (A–Z)',
  '-code': 'kode (Z–A)',
  created_at: 'terlama dibuat',
  '-created_at': 'terbaru dibuat',
  updated_at: 'terlama diubah',
  '-updated_at': 'terbaru diubah',
  status: 'status (A–Z)',
  '-status': 'status (Z–A)',
}

function CreateTemplateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (t: ReportTemplate) => void }) {
  const [form, setForm] = useState({ code: '', name: '', description: '', category: '', data_source: 'asset' as TemplateDataSource })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const create = useMutation({
    mutationFn: () => templatesApi.create({ ...form, description: form.description || null, category: form.category || null }),
    onSuccess: (res) => {
      toast.success('Template dibuat', 'Versi draft awal sudah tersedia — lanjutkan di desainer.')
      setForm({ code: '', name: '', description: '', category: '', data_source: 'asset' })
      setErrors({})
      onClose()
      onCreated(res.data)
    },
    onError: (e) => {
      const err = e as { fields?: Record<string, string[]>; message?: string }
      if (err.fields && Object.keys(err.fields).length) {
        setErrors(Object.fromEntries(Object.entries(err.fields).map(([k, v]) => [k, v[0] ?? 'Tidak valid'])))
      } else {
        toast.error('Gagal membuat template', errorMessage(e))
      }
    },
  })

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Modal
      open={open}
      title="Template Laporan Baru"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Batal</Button>
          <Button loading={create.isPending} onClick={() => create.mutate()} disabled={form.code.trim().length < 2 || form.name.trim().length < 2}>
            Buat & Buka Desainer
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        <Field label="Nama template" required error={errors.name}>
          {(p) => <Input {...p} value={form.name} onChange={set('name')} maxLength={150} placeholder="Laporan Stok Aset" />}
        </Field>
        <Field label="Kode" required error={errors.code} hint="Huruf, angka, strip — unik per organisasi.">
          {(p) => <Input {...p} value={form.code} onChange={set('code')} maxLength={60} placeholder="STOCK-01" className="font-mono" />}
        </Field>
      </div>
      <Field label="Sumber data" required error={errors.data_source} hint="Tetap untuk seluruh versi template.">
        {(p) => (
          <Select {...p} value={form.data_source} onChange={set('data_source')}>
            {(Object.keys(dataSourceLabel) as TemplateDataSource[]).map((s) => (
              <option key={s} value={s}>{dataSourceLabel[s]}</option>
            ))}
          </Select>
        )}
      </Field>
      <Field label="Kategori" error={errors.category}>
        {(p) => <Input {...p} value={form.category} onChange={set('category')} maxLength={60} placeholder="Inventaris, Keuangan, …" />}
      </Field>
      <Field label="Deskripsi" error={errors.description}>
        {(p) => <Textarea {...p} value={form.description} onChange={set('description')} rows={2} maxLength={2000} />}
      </Field>
    </Modal>
  )
}
