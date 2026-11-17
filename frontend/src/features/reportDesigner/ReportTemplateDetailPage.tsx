import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, ArrowLeft, Copy, FileCheck2, PencilRuler } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { DetailSkeleton, EmptyState, ErrorState } from '../../components/ui/Feedback'
import { DataTable, type Column } from '../../components/ui/Table'
import { toast } from '../../components/ui/toast'
import { errorMessage } from '../../lib/forms'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { dataSourceLabel, templateStatusLabel, templateStatusTone, templatesApi, type TemplateVersion } from './api'

type PendingAction = 'duplicate' | 'publish' | 'archive' | null

/** Template detail: metadata, lifecycle actions and the version history. */
export function ReportTemplateDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [pending, setPending] = useState<PendingAction>(null)

  const templateQuery = useQuery({ queryKey: ['report-template', id], queryFn: () => templatesApi.get(id!), enabled: Boolean(id) })
  const versionsQuery = useQuery({ queryKey: ['report-template-versions', id], queryFn: () => templatesApi.versions(id!), enabled: Boolean(id) })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['report-template', id] })
    void queryClient.invalidateQueries({ queryKey: ['report-template-versions', id] })
    void queryClient.invalidateQueries({ queryKey: ['/report-templates'] })
  }

  const action = useMutation({
    mutationFn: (kind: Exclude<PendingAction, null>) => {
      const t = templateQuery.data!.data
      if (kind === 'duplicate') return templatesApi.duplicate(t.id)
      if (kind === 'publish') return templatesApi.publish(t.id)
      return templatesApi.archive(t.id)
    },
    onSuccess: (res, kind) => {
      const messages = { duplicate: 'Template diduplikasi', publish: 'Template diterbitkan', archive: 'Template diarsipkan' } as const
      setPending(null)
      toast.saved(res, messages[kind])
      invalidate()
      if (kind === 'duplicate') navigate(`/laporan/template/${res.data.id}`)
    },
    onError: (e) => toast.error('Aksi gagal', errorMessage(e)),
  })

  if (templateQuery.isLoading) return <DetailSkeleton label="Memuat template…" />
  if (templateQuery.isError) return <ErrorState error={templateQuery.error} onRetry={() => void templateQuery.refetch()} />
  const t = templateQuery.data!.data

  const versions = [...(versionsQuery.data?.data ?? [])].sort((a, b) => b.version_number - a.version_number)
  const canPublish = can('report.publish') && t.status !== 'archived' && versions.some((v) => v.status === 'draft')

  const versionColumns: Column<TemplateVersion>[] = [
    { key: 'version_number', header: 'Versi', className: 'w-20', cell: (v) => <span className="font-semibold tabular-nums">v{v.version_number}</span> },
    { key: 'status', header: 'Status', className: 'w-32', cell: (v) => <Badge tone={v.status === 'published' ? 'green' : v.status === 'draft' ? 'amber' : 'slate'}>{templateStatusLabel[v.status]}</Badge> },
    { key: 'change_note', header: 'Catatan', className: 'whitespace-normal', cell: (v) => <span className="text-slate-600">{v.change_note ?? <span className="text-slate-400">—</span>}</span> },
    { key: 'created_by', header: 'Oleh', className: 'w-40 hideOnMobile', cell: (v) => <span className="text-slate-600">{v.created_by ?? '—'}</span>, hideOnMobile: true },
    { key: 'published_at', header: 'Diterbitkan', className: 'w-40 hideOnMobile', cell: (v) => <span className="tabular-nums text-slate-600">{v.published_at ? formatDate(v.published_at) : '—'}</span>, hideOnMobile: true },
    { key: 'created_at', header: 'Dibuat', className: 'w-36 hideOnMobile', cell: (v) => <span className="tabular-nums text-slate-600">{formatDate(v.created_at)}</span>, hideOnMobile: true },
  ]

  return (
    <>
      <PageHeader
        title={t.name}
        badges={
          <>
            <Badge tone={templateStatusTone[t.status]}>{templateStatusLabel[t.status]}</Badge>
            <Badge tone="blue">{t.code}</Badge>
          </>
        }
        description={t.description ?? undefined}
        meta={
          <>
            <span>Sumber data: {dataSourceLabel[t.data_source]}</span>
            {t.category && <span>Kategori: {t.category}</span>}
            <span>Dibuat: {formatDate(t.created_at)}</span>
            <span>Diperbarui: {formatDate(t.updated_at)}</span>
            {t.created_by && <span>Oleh: {t.created_by}</span>}
          </>
        }
        actions={
          <>
            <ButtonLink to="/laporan/template" variant="secondary" size="sm">
              <ArrowLeft className="size-4" aria-hidden /> Semua Template
            </ButtonLink>
            {can('report.update') && (
              <Button onClick={() => navigate(`/laporan/template/${t.id}/desain`)}>
                <PencilRuler className="size-4" aria-hidden /> Buka Desainer
              </Button>
            )}
            {can('report.create') && (
              <Button variant="secondary" onClick={() => setPending('duplicate')}>
                <Copy className="size-4" aria-hidden /> Duplikat
              </Button>
            )}
            {canPublish && (
              <Button variant="secondary" onClick={() => setPending('publish')}>
                <FileCheck2 className="size-4" aria-hidden /> Terbitkan Draft
              </Button>
            )}
            {can('report.publish') && t.status !== 'archived' && (
              <Button variant="secondary" onClick={() => setPending('archive')}>
                <Archive className="size-4" aria-hidden /> Arsipkan
              </Button>
            )}
          </>
        }
      />

      {t.status === 'archived' && (
        <p className="mb-4 rounded-lg bg-slate-50 px-4 py-2.5 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
          Template ini diarsipkan — tidak dapat digunakan untuk generate laporan baru.
        </p>
      )}

      <Card title="Riwayat Versi" className="mb-6">
        {versionsQuery.isLoading ? (
          <DetailSkeleton label="Memuat versi…" />
        ) : versionsQuery.isError ? (
          <ErrorState error={versionsQuery.error} onRetry={() => void versionsQuery.refetch()} />
        ) : versions.length === 0 ? (
          <EmptyState title="Belum ada versi" description="Versi draft dibuat saat template disimpan pertama kali." />
        ) : (
          <DataTable columns={versionColumns} rows={versions} />
        )}
      </Card>

      <ConfirmDialog
        open={pending !== null}
        title={pending === 'duplicate' ? 'Duplikat Template?' : pending === 'publish' ? 'Terbitkan Versi?' : 'Arsipkan Template?'}
        confirmLabel={pending === 'duplicate' ? 'Duplikat' : pending === 'publish' ? 'Terbitkan' : 'Arsipkan'}
        loading={action.isPending}
        error={action.isError ? action.error : undefined}
        onConfirm={() => pending && action.mutate(pending)}
        onClose={() => setPending(null)}
      >
        {pending === 'duplicate' && 'Salinan draft dibuat dengan desain versi terbaru. Template asli tidak berubah.'}
        {pending === 'publish' && 'Versi draft dibekukan dan menjadi versi aktif untuk generate laporan.'}
        {pending === 'archive' && 'Template tidak dapat dipakai generate laporan baru. Data dan riwayat export tetap tersimpan.'}
      </ConfirmDialog>
    </>
  )
}
