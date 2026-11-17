import { DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, FileCheck2, Minus, Plus, Redo2, Save, Settings2, ShieldCheck, Undo2 } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { errorMessage } from '../../lib/forms'
import { useAuth } from '../../lib/auth/context'
import { dataSourceLabel, templateStatusLabel, templateStatusTone, templatesApi, type ReportField, type ReportTemplate } from './api'
import { DesignerCanvas } from './designer/DesignerCanvas'
import { ElementsPanel } from './designer/ElementsPanel'
import { PageSettingsModal } from './designer/PageSettingsModal'
import { PropertiesPanel } from './designer/PropertiesPanel'
import { useDesigner } from './designer/useDesigner'
import { binding, clampToContent, createElement, defaultDesign, TEXTUAL_TYPES, type ElementType } from './design'
import { clientToContentMm } from './designer/canvasGeometry'

const ZOOM_STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2]

/** Client coordinates of a drag end: pointer position = activator + delta. */
function dragEndPoint(event: DragEndEvent): { x: number; y: number } | null {
  const ae = event.activatorEvent as PointerEvent | MouseEvent | TouchEvent | undefined
  if (!ae) return null
  if ('changedTouches' in ae) {
    const t = ae.changedTouches[0]
    return t ? { x: t.clientX + event.delta.x, y: t.clientY + event.delta.y } : null
  }
  return { x: ae.clientX + event.delta.x, y: ae.clientY + event.delta.y }
}

/**
 * Visual Report Designer: palette + data fields on the left, the page canvas in the
 * middle, properties on the right. Saves the working document as the template's draft
 * version; publishing freezes it (immutable, enforced by the backend).
 */
export function ReportDesignerPage() {
  const { id } = useParams<{ id: string }>()
  const templateQuery = useQuery({ queryKey: ['report-template', id], queryFn: () => templatesApi.get(id!), enabled: Boolean(id) })
  const versionsQuery = useQuery({ queryKey: ['report-template-versions', id], queryFn: () => templatesApi.versions(id!), enabled: Boolean(id) })
  const fieldsQuery = useQuery({ queryKey: ['report-fields'], queryFn: templatesApi.fields, staleTime: 5 * 60 * 1000 })

  if (templateQuery.isLoading || versionsQuery.isLoading) return <Spinner label="Memuat desainer…" />
  if (templateQuery.isError) return <ErrorState error={templateQuery.error} onRetry={() => void templateQuery.refetch()} />
  if (versionsQuery.isError) return <ErrorState error={versionsQuery.error} onRetry={() => void versionsQuery.refetch()} />

  const template = templateQuery.data!.data
  const versions = [...(versionsQuery.data?.data ?? [])].sort((a, b) => b.version_number - a.version_number)
  const draft = versions.find((v) => v.status === 'draft') ?? versions[0]

  return (
    <DesignerEditor
      key={template.id}
      template={template}
      initialDesign={draft?.design ?? defaultDesign()}
      versionLabel={draft ? `v${draft.version_number}` : 'belum ada versi'}
      fields={fieldsQuery.data?.data}
    />
  )
}

function DesignerEditor({ template, initialDesign, versionLabel, fields }: { template: ReportTemplate; initialDesign: ReturnType<typeof defaultDesign>; versionLabel: string; fields?: Record<string, ReportField[]> }) {
  const designer = useDesigner(initialDesign)
  const { can } = useAuth()
  const editable = can('report.update')
  const queryClient = useQueryClient()
  const pageRef = useRef<HTMLDivElement | null>(null)
  const [pageSettingsOpen, setPageSettingsOpen] = useState(false)
  const [saveOpen, setSaveOpen] = useState(false)
  const [changeNote, setChangeNote] = useState('')
  const [confirmPublish, setConfirmPublish] = useState(false)
  const [validateOpen, setValidateOpen] = useState(false)
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }))

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['report-template', template.id] })
    void queryClient.invalidateQueries({ queryKey: ['report-template-versions', template.id] })
    void queryClient.invalidateQueries({ queryKey: ['report-templates'] })
  }

  const saveMutation = useMutation({
    mutationFn: (note: string) => templatesApi.update(template.id, { design: designer.current.current, change_note: note || undefined }),
    onSuccess: (res) => {
      designer.markSaved()
      setSaveOpen(false)
      setChangeNote('')
      toast.saved(res, 'Desain disimpan', `Versi draft ${versionLabel} diperbarui.`)
      invalidate()
    },
    onError: (e) => toast.error('Gagal menyimpan', errorMessage(e)),
  })

  const publishMutation = useMutation({
    mutationFn: () => templatesApi.publish(template.id),
    onSuccess: () => {
      setConfirmPublish(false)
      toast.success('Template diterbitkan', 'Versi draft dibekukan dan menjadi versi aktif.')
      invalidate()
    },
    onError: (e) => toast.error('Gagal menerbitkan', errorMessage(e)),
  })

  // Validates the live canvas document (unsaved changes included) against the Field Registry.
  const validateMutation = useMutation({
    mutationFn: () => templatesApi.validate(template.id, designer.current.current),
    onSuccess: () => setValidateOpen(true),
    onError: (e) => toast.error('Validasi gagal', errorMessage(e)),
  })

  const sourceFields = fields?.[template.data_source] ?? []
  const contextFields = fields?.context ?? []
  const fieldGroups = useMemo(
    () => [
      { key: template.data_source, title: dataSourceLabel[template.data_source], fields: sourceFields },
      { key: 'context', title: 'Konteks laporan', fields: contextFields },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [template.data_source, fields],
  )

  const cascade = () => {
    const n = designer.doc.elements.length
    return { x: 15 + (n % 5) * 6, y: 15 + (n % 5) * 4 }
  }

  const addElement = (type: ElementType) => {
    if (!editable) return
    const pos = cascade()
    designer.addElement(createElement(type, template.data_source, pos.x, pos.y))
  }

  const insertBinding = (fieldKey: string) => {
    if (!editable) return
    const sel = designer.selected
    if (sel && TEXTUAL_TYPES.has(sel.type)) {
      const current = sel.props.text ?? ''
      const sep = current && !current.endsWith(' ') && !current.endsWith('\n') ? ' ' : ''
      designer.patchElement(sel.id, { props: { text: `${current}${sep}${binding(fieldKey)}` } })
      return
    }
    const pos = cascade()
    const el = createElement('dynamic_text', template.data_source, pos.x, pos.y)
    designer.addElement({ ...el, props: { text: binding(fieldKey) } })
  }

  const dropAt = (clientX: number, clientY: number) => {
    const pageEl = pageRef.current
    if (!pageEl) return null
    const rect = pageEl.getBoundingClientRect()
    return clientToContentMm(rect, clientX, clientY, designer.doc.page, designer.zoom)
  }

  const handleDragEnd = (event: DragEndEvent) => {
    if (!editable || event.over?.id !== 'designer-canvas') return
    const data = event.active.data.current as { kind?: string; type?: ElementType; fieldKey?: string } | undefined
    const point = dragEndPoint(event)
    if (!data || !point) return
    const mm = dropAt(point.x, point.y)
    if (!mm) return
    if (data.kind === 'palette' && data.type) {
      const el = createElement(data.type, template.data_source, mm.x, mm.y)
      const clamped = clampToContent(el, designer.doc.page)
      designer.addElement({ ...el, x: clamped.x, y: clamped.y })
    } else if (data.kind === 'field' && data.fieldKey) {
      const el = createElement('dynamic_text', template.data_source, mm.x, mm.y)
      const clamped = clampToContent(el, designer.doc.page)
      designer.addElement({ ...el, x: clamped.x, y: clamped.y, props: { text: binding(data.fieldKey) } })
    }
  }

  const zoomIndex = ZOOM_STEPS.indexOf(designer.zoom)
  const zoomBy = (dir: 1 | -1) => {
    const next = ZOOM_STEPS[Math.min(ZOOM_STEPS.length - 1, Math.max(0, (zoomIndex < 0 ? ZOOM_STEPS.indexOf(1) : zoomIndex) + dir))]
    designer.setZoom(next)
  }

  return (
    <>
      <PageHeader
        title={template.name}
        badges={
          <>
            <Badge tone={templateStatusTone[template.status]}>{templateStatusLabel[template.status]}</Badge>
            <Badge tone="blue">{template.code}</Badge>
            {designer.dirty && editable && <Badge tone="amber">Belum disimpan</Badge>}
          </>
        }
        meta={
          <>
            <span>Sumber data: {dataSourceLabel[template.data_source]}</span>
            <span>Versi kerja: {versionLabel}</span>
            <span>{designer.doc.elements.length} elemen</span>
          </>
        }
        actions={
          <>
            <ButtonLink to={`/laporan/template/${template.id}`} variant="secondary" size="sm">
              <ArrowLeft className="size-4" aria-hidden /> Kembali
            </ButtonLink>
            {editable && (
              <>
                <Button variant="secondary" size="sm" onClick={designer.undo} disabled={!designer.canUndo} aria-label="Urungkan">
                  <Undo2 className="size-4" aria-hidden />
                </Button>
                <Button variant="secondary" size="sm" onClick={designer.redo} disabled={!designer.canRedo} aria-label="Ulangi">
                  <Redo2 className="size-4" aria-hidden />
                </Button>
                <span className="mx-1 hidden items-center gap-1 sm:flex" aria-label="Zoom">
                  <Button variant="ghost" size="sm" onClick={() => zoomBy(-1)} disabled={zoomIndex <= 0} aria-label="Perkecil">
                    <Minus className="size-4" aria-hidden />
                  </Button>
                  <span className="w-12 text-center text-xs tabular-nums text-slate-500">{Math.round(designer.zoom * 100)}%</span>
                  <Button variant="ghost" size="sm" onClick={() => zoomBy(1)} disabled={zoomIndex === ZOOM_STEPS.length - 1} aria-label="Perbesar">
                    <Plus className="size-4" aria-hidden />
                  </Button>
                </span>
                <Button variant="secondary" size="sm" onClick={() => setPageSettingsOpen(true)}>
                  <Settings2 className="size-4" aria-hidden /> Halaman
                </Button>
                <Button variant="secondary" size="sm" onClick={() => validateMutation.mutate()} loading={validateMutation.isPending}>
                  <ShieldCheck className="size-4" aria-hidden /> Validasi
                </Button>
                <Button variant="secondary" size="sm" onClick={() => setSaveOpen(true)} disabled={!designer.dirty}>
                  <Save className="size-4" aria-hidden /> Simpan
                </Button>
                {can('report.publish') && (
                  <Button variant="primary" size="sm" onClick={() => setConfirmPublish(true)}>
                    <FileCheck2 className="size-4" aria-hidden /> Terbitkan
                  </Button>
                )}
              </>
            )}
          </>
        }
      />

      <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
        <div className="flex h-[calc(100dvh-16rem)] min-h-[32rem] overflow-hidden rounded-xl bg-white shadow-card ring-1 ring-slate-200/80">
          <ElementsPanel fieldGroups={fieldGroups} onAddElement={addElement} onInsertBinding={insertBinding} />
          <DesignerCanvas
            doc={designer.doc}
            selectedId={designer.selectedId}
            editable={editable}
            zoom={designer.zoom}
            pageRef={pageRef}
            onSelect={designer.select}
            onMoveElement={(eid, x, y, transient) => designer.patchElement(eid, { x, y }, transient)}
          />
          <PropertiesPanel designer={designer} sourceFields={sourceFields} allFields={[...sourceFields, ...contextFields]} editable={editable} onOpenPageSettings={() => setPageSettingsOpen(true)} />
        </div>
      </DndContext>

      <PageSettingsModal open={pageSettingsOpen} page={designer.doc.page} onClose={() => setPageSettingsOpen(false)} onApply={(page) => designer.updatePage(page)} />

      <Modal
        open={saveOpen}
        title="Simpan Perubahan Desain"
        onClose={() => setSaveOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSaveOpen(false)}>Batal</Button>
            <Button loading={saveMutation.isPending} onClick={() => saveMutation.mutate(changeNote.trim())}>Simpan Versi</Button>
          </>
        }
      >
        <p className="text-sm text-slate-600">
          Perubahan disimpan ke versi draft <span className="font-semibold">{versionLabel}</span>. Versi yang sudah diterbitkan tidak berubah.
        </p>
        <Field label="Catatan perubahan" hint="Opsional — tampil di riwayat versi.">
          {(p) => <Textarea {...p} value={changeNote} onChange={(e) => setChangeNote(e.target.value)} rows={3} maxLength={500} placeholder="Contoh: menambah kolom lokasi" />}
        </Field>
        {saveMutation.isError && <p className="text-sm text-red-700">{errorMessage(saveMutation.error)}</p>}
      </Modal>

      <Modal
        open={validateOpen}
        title="Validasi Desain"
        onClose={() => setValidateOpen(false)}
        footer={<Button variant="secondary" onClick={() => setValidateOpen(false)}>Tutup</Button>}
      >
        {validateMutation.data && (
          <div className="space-y-3 text-sm">
            {validateMutation.data.data.valid ? (
              <p className="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 ring-1 ring-emerald-200">
                Desain valid — seluruh binding dikenali Field Registry ({validateMutation.data.data.bindings.length} field).
              </p>
            ) : (
              <p className="rounded-lg bg-red-50 px-3 py-2 text-red-800 ring-1 ring-red-200">
                Desain memiliki kesalahan yang harus diperbaiki sebelum diterbitkan.
              </p>
            )}
            {validateMutation.data.data.issues.length > 0 && (
              <ul className="max-h-72 space-y-2 overflow-y-auto">
                {validateMutation.data.data.issues.map((issue, i) => (
                  <li key={i} className="rounded-lg border border-slate-200 px-3 py-2">
                    <div className="flex items-center gap-2">
                      <Badge tone={issue.severity === 'error' ? 'red' : 'amber'}>{issue.severity === 'error' ? 'Error' : 'Peringatan'}</Badge>
                      <span className="font-mono text-xs text-slate-500">{issue.code}</span>
                    </div>
                    <p className="mt-1 text-slate-700">{issue.message}</p>
                    <p className="mt-0.5 font-mono text-xs text-slate-400">{issue.path}</p>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={confirmPublish}
        title="Terbitkan Versi Template?"
        confirmLabel="Terbitkan"
        loading={publishMutation.isPending}
        error={publishMutation.isError ? publishMutation.error : undefined}
        onConfirm={() => publishMutation.mutate()}
        onClose={() => setConfirmPublish(false)}
      >
        Versi draft saat ini dibekukan dan menjadi versi aktif yang dipakai saat generate laporan. Perubahan berikutnya akan membuat versi draft baru.
      </ConfirmDialog>
    </>
  )
}

