import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Download, FileSpreadsheet, FileText, RotateCcw, SearchX } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import { toOptions, useOptions } from '../master/options'
import {
  dataSourceLabel,
  formatLabel,
  runApi,
  templatesApi,
  templateStatusLabel,
  templateStatusTone,
  type ExportFormat,
  type ReportExportRow,
  type RunFilterDefinition,
  type RunFilterField,
  type RunFilters,
} from './api'
import { DEFAULT_PAGE } from './design'
import { PreviewSheet } from './run/PreviewSheet'

/** Date pairs that must not end before they start, keyed by the "to" field. */
function rangeErrors(fields: RunFilterField[], values: RunFilters): Record<string, string> {
  const errors: Record<string, string> = {}
  const byRange = new Map<string, RunFilterField[]>()
  for (const f of fields) if (f.range) byRange.set(f.range, [...(byRange.get(f.range) ?? []), f])
  for (const [, pair] of byRange) {
    const [from, to] = pair
    const a = from && values[from.key]
    const b = to && values[to.key]
    if (to && typeof a === 'string' && typeof b === 'string' && a && b && b < a) errors[to.key] = 'Tanggal akhir tidak boleh sebelum tanggal awal.'
  }
  return errors
}

function FilterInput({ field, value, error, onChange }: { field: RunFilterField; value: RunFilters[string]; error?: string; onChange: (value: string | boolean) => void }) {
  const categories = useOptions('/tenant-masters/categories', {}, field.lookup === 'categories')
  const locations = useOptions('/locations', {}, field.lookup === 'locations')

  if (field.type === 'boolean') {
    return (
      <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
        <input type="checkbox" className="size-4 rounded border-slate-300" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
        {field.label}
      </label>
    )
  }
  const text = typeof value === 'string' ? value : ''
  const options = field.type === 'lookup' ? toOptions((field.lookup === 'categories' ? categories : locations).data) : (field.options ?? [])
  return (
    <Field label={field.label} error={error}>
      {(p) =>
        field.type === 'select' || field.type === 'lookup' ? (
          <select {...p} className={`${p.className} pr-8`} value={text} onChange={(e) => onChange(e.target.value)}>
            <option value="">Semua</option>
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        ) : (
          <input {...p} type={field.type === 'date' ? 'date' : 'search'} value={text} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value)} />
        )
      }
    </Field>
  )
}

function FiltersCard({
  definition,
  values,
  errors,
  onChange,
  onApply,
  onReset,
}: {
  definition: RunFilterDefinition
  values: RunFilters
  errors: Record<string, string>
  onChange: (key: string, value: string | boolean) => void
  onApply: () => void
  onReset: () => void
}) {
  const submit = (e: FormEvent) => {
    e.preventDefault()
    onApply()
  }
  const errorCount = Object.keys(errors).length
  return (
    <Card
      title="Filter"
      actions={
        <Button variant="ghost" size="sm" onClick={onReset}>
          <RotateCcw className="size-3.5" aria-hidden /> Kembalikan ke bawaan
        </Button>
      }
    >
      <form onSubmit={submit} className="space-y-4 p-4" noValidate>
        {errorCount > 0 && <Alert tone="error">Periksa {errorCount} isian.</Alert>}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
          {definition.fields.map((f) => (
            <div key={f.key} className={f.type === 'text' ? 'sm:col-span-2 lg:col-span-1 xl:col-span-2' : undefined}>
              <FilterInput field={f} value={values[f.key]} error={errors[f.key]} onChange={(v) => onChange(f.key, v)} />
              {definition.defaults[f.key] !== undefined && values[f.key] !== definition.defaults[f.key] && (
                <p className="mt-1 text-xs text-slate-500">Bawaan template: {describeDefault(f, definition.defaults[f.key])}</p>
              )}
            </div>
          ))}
        </div>
        <Button type="submit" className="w-full sm:w-auto">
          Terapkan filter
        </Button>
      </form>
    </Card>
  )
}

function describeDefault(field: RunFilterField, value: RunFilters[string]): string {
  if (typeof value === 'boolean') return value ? 'ya' : 'tidak'
  return field.options?.find((o) => o.value === value)?.label ?? String(value ?? '')
}

function HistoryCard({ templateId, maxRows }: { templateId: string; maxRows: number | null }) {
  const { can } = useAuth()
  const [page, setPage] = useState(1)
  const query = useQuery({
    queryKey: ['report-exports', templateId, page],
    queryFn: () => runApi.history({ template_id: templateId, page }),
    placeholderData: (prev) => prev,
  })
  const download = useMutation({
    mutationFn: (row: ReportExportRow) => runApi.download(row),
    onError: (e) => toast.error('Unduhan gagal', errorMessage(e)),
  })

  const columns: Column<ReportExportRow>[] = [
    {
      key: 'export_number',
      header: 'Nomor',
      cell: (r) => (
        <div>
          <span className="font-medium tabular-nums text-slate-900">{r.export_number}</span>
          <span className="block text-xs text-slate-500">
            {formatLabel[r.format]}
            {r.version_number ? ` · v${r.version_number}` : ''}
          </span>
        </div>
      ),
    },
    { key: 'filters', header: 'Filter', className: 'whitespace-normal', cell: (r) => <span className="text-slate-600">{r.filter_description.join(' · ') || 'Tanpa filter'}</span>, hideOnMobile: true },
    { key: 'row_count', header: 'Baris', className: 'w-20 text-right', cell: (r) => <span className="tabular-nums">{r.row_count ?? '—'}</span> },
    {
      key: 'created_at',
      header: 'Dibuat',
      className: 'w-44',
      cell: (r) => (
        <span className="text-slate-600">
          {r.created_by ?? '—'}
          <span className="block text-xs tabular-nums text-slate-500">{formatDateTime(r.created_at)}</span>
        </span>
      ),
      hideOnMobile: true,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-32 text-right',
      cell: (r) =>
        r.downloadable ? (
          can('report.download') && (
            <Button variant="secondary" size="sm" loading={download.isPending && download.variables?.id === r.id} onClick={() => download.mutate(r)}>
              <Download className="size-3.5" aria-hidden /> Unduh
            </Button>
          )
        ) : (
          <Badge tone="slate">{r.status === 'failed' ? 'Gagal' : 'Kedaluwarsa'}</Badge>
        ),
    },
  ]

  const rows = query.data?.data ?? []
  return (
    <Card title="Riwayat unduhan" actions={<span className="text-xs text-slate-500">File disimpan 30 hari{maxRows ? ` · maks. ${maxRows} baris per laporan` : ''}</span>}>
      {query.isLoading ? (
        <Spinner label="Memuat riwayat…" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState title="Belum ada unduhan" description="Laporan yang Anda unduh tercatat di sini dan dapat diunduh ulang selama 30 hari." />
      ) : (
        <DataTable columns={columns} rows={rows} />
      )}
      {query.data?.meta && query.data.meta.last_page > 1 && <Pagination meta={query.data.meta} onPage={setPage} />}
    </Card>
  )
}

/**
 * Running a published template (F-05 §3): its filters, a live preview of the first page,
 * PDF/Excel downloads recorded in the history, and re-downloads within 30 days.
 */
export function ReportRunPage() {
  const { id } = useParams<{ id: string }>()
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const templateQuery = useQuery({ queryKey: ['report-template', id], queryFn: () => templatesApi.get(id!), enabled: Boolean(id) })
  const versionsQuery = useQuery({ queryKey: ['report-template-versions', id], queryFn: () => templatesApi.versions(id!), enabled: Boolean(id) })
  const definitionQuery = useQuery({ queryKey: ['report-run-filters', id], queryFn: () => runApi.filters(id!), enabled: Boolean(id) })
  const definition = definitionQuery.data?.data

  // `draft` is what the form shows; `applied` is what the preview and the downloads use.
  const [draft, setDraft] = useState<RunFilters | null>(null)
  const [applied, setApplied] = useState<RunFilters | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const values = draft ?? definition?.defaults ?? {}
  const activeFilters = applied ?? definition?.defaults ?? null

  const previewQuery = useQuery({
    queryKey: ['report-run-preview', id, activeFilters],
    queryFn: () => runApi.preview(id!, activeFilters ?? {}),
    enabled: Boolean(id) && activeFilters !== null,
    placeholderData: (prev) => prev,
  })

  const generate = useMutation({
    mutationFn: (format: ExportFormat) => runApi.generate(id!, format, activeFilters ?? {}),
    onSuccess: async (res) => {
      void queryClient.invalidateQueries({ queryKey: ['report-exports', id] })
      toast.success('Laporan siap diunduh', `${res.data.export_number} · ${formatLabel[res.data.format]} · ${res.data.row_count ?? 0} baris`)
      if (can('report.download')) {
        try {
          await runApi.download(res.data)
        } catch (e) {
          toast.error('Unduhan gagal', errorMessage(e))
        }
      }
    },
    onError: (e) => toast.error('Laporan gagal dibuat', errorMessage(e)),
  })

  if (templateQuery.isLoading || definitionQuery.isLoading) return <DetailSkeleton label="Memuat laporan…" />
  if (templateQuery.isError) return <ErrorState error={templateQuery.error} onRetry={() => void templateQuery.refetch()} />
  const template = templateQuery.data!.data

  if (definitionQuery.isError) {
    const notPublished = definitionQuery.error instanceof ApiError && definitionQuery.error.code === 'TEMPLATE_NOT_PUBLISHED'
    return (
      <>
        <PageHeader title={template.name} actions={<ButtonLink to={`/laporan/template/${template.id}`} variant="secondary" size="sm"><ArrowLeft className="size-4" aria-hidden /> Detail template</ButtonLink>} />
        {notPublished ? (
          <Card>
            <EmptyState title="Template belum diterbitkan" description="Terbitkan template di desainer terlebih dahulu, lalu jalankan laporannya di sini." />
          </Card>
        ) : (
          <ErrorState error={definitionQuery.error} onRetry={() => void definitionQuery.refetch()} />
        )}
      </>
    )
  }
  const def = definition!

  const activeVersion = versionsQuery.data?.data.find((v) => v.version_number === template.active_version)
  const page = activeVersion?.design?.page ?? DEFAULT_PAGE
  const preview = previewQuery.data?.data
  const tooLarge = preview ? preview.total > def.max_rows : false

  const change = (key: string, value: string | boolean) => {
    setDraft({ ...values, [key]: value })
    if (errors[key]) setErrors(({ [key]: _removed, ...rest }) => rest)
  }
  const apply = () => {
    const found = rangeErrors(def.fields, values)
    setErrors(found)
    if (Object.keys(found).length === 0) setApplied({ ...values })
  }
  const reset = () => {
    setDraft(null)
    setApplied(null)
    setErrors({})
  }

  const canDownload = can('report.generate') && preview !== undefined && !tooLarge && Object.keys(errors).length === 0

  return (
    <>
      <PageHeader
        title={template.name}
        badges={
          <>
            <Badge tone={templateStatusTone[template.status]}>{templateStatusLabel[template.status]}{template.active_version ? ` v${template.active_version}` : ''}</Badge>
            <Badge tone="blue">{template.code}</Badge>
          </>
        }
        meta={<span>Sumber data: {dataSourceLabel[template.data_source]}</span>}
        actions={
          <>
            <ButtonLink to={`/laporan/template/${template.id}`} variant="secondary" size="sm">
              <ArrowLeft className="size-4" aria-hidden /> Detail template
            </ButtonLink>
            <Button variant="secondary" disabled={!canDownload || generate.isPending} loading={generate.isPending && generate.variables === 'xlsx'} onClick={() => generate.mutate('xlsx')}>
              <FileSpreadsheet className="size-4" aria-hidden /> Unduh Excel
            </Button>
            <Button disabled={!canDownload || generate.isPending} loading={generate.isPending && generate.variables === 'pdf'} onClick={() => generate.mutate('pdf')}>
              <FileText className="size-4" aria-hidden /> Unduh PDF
            </Button>
          </>
        }
      />

      <div className="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <div className="space-y-6">
          <FiltersCard definition={def} values={values} errors={errors} onChange={change} onApply={apply} onReset={reset} />
        </div>

        <Card
          title="Pratinjau"
          actions={
            preview && (
              <span className="text-xs text-slate-500">
                {preview.total} baris · {page.size} {page.orientation === 'landscape' ? 'mendatar' : 'tegak'}
              </span>
            )
          }
        >
          <div className="space-y-4 bg-slate-100/70 p-4">
            {Object.keys(errors).length > 0 ? (
              <EmptyState icon={SearchX} title="Perbaiki filter untuk melihat pratinjau" />
            ) : previewQuery.isLoading ? (
              <Spinner label="Memuat pratinjau…" />
            ) : previewQuery.isError ? (
              <ErrorState error={previewQuery.error} onRetry={() => void previewQuery.refetch()} />
            ) : preview ? (
              <>
                {preview.filter_description.length > 0 && <p className="text-sm text-slate-600">Filter: {preview.filter_description.join(' · ')}</p>}
                {tooLarge && (
                  <Alert tone="warning" title={`Menampilkan ${def.max_rows} dari ${preview.total.toLocaleString('id-ID')} baris`}>
                    Satu laporan memuat paling banyak {def.max_rows} baris. Persempit tanggal atau filter lain sebelum mengunduh.
                  </Alert>
                )}
                {preview.restricted_fields.length > 0 && (
                  <Alert tone="info">Sebagian kolom ditampilkan sebagai "—" karena Anda tidak memiliki izin untuk melihatnya.</Alert>
                )}
                {preview.total === 0 && (
                  <Alert tone="info" title="Tidak ada data untuk filter ini">
                    Ubah tanggal atau filter. Laporan kosong tetap dapat diunduh.
                  </Alert>
                )}
                <PreviewSheet page={page} elements={preview.elements} />
                {previewQuery.isFetching && <p className="text-center text-xs text-slate-500">Memperbarui pratinjau…</p>}
              </>
            ) : null}
          </div>
        </Card>
      </div>

      <div className="mt-6">
        <HistoryCard templateId={template.id} maxRows={def.max_rows} />
      </div>
    </>
  )
}
