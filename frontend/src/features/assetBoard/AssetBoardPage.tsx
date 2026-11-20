import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Eye, EyeOff, Plus, RotateCcw, Settings2, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Button } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, ErrorState, Skeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { IconButton } from '../../components/ui/IconButton'
import { PillTabs } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { boardApi, heightOf, WIDTH_LABEL, WIDTHS, widthOf, type CatalogEntry, type DashboardWidget, type Width } from './api'
import { WidgetBody } from './WidgetBody'
import { AddWidgetModal, WidgetSettingsModal } from './WidgetModals'

/** Grid classes per width: 12 columns on wide screens, 6 on tablets, one on phones. */
const SPAN: Record<Width, string> = {
  3: 'md:col-span-3 xl:col-span-3',
  4: 'md:col-span-3 xl:col-span-4',
  6: 'md:col-span-6 xl:col-span-6',
  8: 'md:col-span-6 xl:col-span-8',
  12: 'md:col-span-6 xl:col-span-12',
}
const MIN_HEIGHT: Record<1 | 2 | 3, string> = { 1: 'min-h-[7.5rem]', 2: 'min-h-[16rem]', 3: 'min-h-[24rem]' }

interface LayoutDraft {
  order: string[]
  width: Record<string, Width>
  visible: Record<string, boolean>
}

/**
 * Dashboard Aset (docs/factory/features/F-06 §4): "Ringkasan" is the member's personal copy of
 * their role's template; each top-level category has its own (shared) dashboard. Layout,
 * "Tambah widget" and widget settings are wide-screen only (D-34) and need edit access.
 */
export function AssetBoardPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('kategori') ?? ''
  const { can } = useAuth()
  const client = useQueryClient()
  const wide = useCanUseEditor()

  const navigation = useQuery({ queryKey: ['/dashboards/navigation'], queryFn: boardApi.navigation, staleTime: 60_000 })
  const catalogQuery = useQuery({ queryKey: ['/widget-catalog'], queryFn: boardApi.catalog, staleTime: 5 * 60_000 })
  const dashboardKey = ['/dashboards', 'board', tab] as const
  const query = useQuery({ queryKey: dashboardKey, queryFn: () => (tab ? boardApi.category(tab) : boardApi.assetBoard()) })

  const [draft, setDraft] = useState<LayoutDraft | null>(null)
  const [adding, setAdding] = useState(false)
  const [settingsFor, setSettingsFor] = useState<DashboardWidget | null>(null)
  const [removing, setRemoving] = useState<DashboardWidget | null>(null)
  const [resetting, setResetting] = useState(false)

  const dashboard = query.data?.data
  const catalog = catalogQuery.data?.data ?? []
  const entryOf = (w: DashboardWidget) => catalog.find((c) => c.widget_key === w.widget_key)
  const canEdit = wide && !!dashboard?.can.edit
  const editing = draft !== null && canEdit
  const categories = (navigation.data?.data.categories ?? []).filter((c) => c.parent_id === null)
  const refresh = () => client.invalidateQueries({ queryKey: ['/dashboards'] })

  // A widget needing a permission the member lacks is not shown at all (F-06 §4.1).
  const permitted = (dashboard?.widgets ?? []).filter((w) => {
    const entry = entryOf(w)
    return w.is_available && (!entry?.required_permission || can(entry.required_permission))
  })
  const order = draft?.order ?? [...permitted].sort((a, b) => a.sort_order - b.sort_order).map((w) => w.id)
  const widgets = order.map((id) => permitted.find((w) => w.id === id)).filter((w): w is DashboardWidget => !!w)
  const visibleOf = (w: DashboardWidget) => draft?.visible[w.id] ?? w.is_visible
  const shown = editing ? widgets : widgets.filter(visibleOf)

  const startEditing = () =>
    dashboard &&
    setDraft({
      order: widgets.map((w) => w.id),
      width: Object.fromEntries(widgets.map((w) => [w.id, widthOf(w, entryOf(w)?.default_layout)])),
      visible: Object.fromEntries(widgets.map((w) => [w.id, w.is_visible])),
    })

  const saveLayout = useMutation({
    mutationFn: async () => {
      if (!dashboard || !draft) return
      const before = [...permitted].sort((a, b) => a.sort_order - b.sort_order).map((w) => w.id)
      if (draft.order.join() !== before.join()) {
        // Widgets the member cannot see keep their place at the end.
        const rest = dashboard.widgets.map((w) => w.id).filter((id) => !draft.order.includes(id))
        await boardApi.reorder(dashboard.id, [...draft.order, ...rest])
      }
      for (const w of permitted) {
        const width = draft.width[w.id]
        const visible = draft.visible[w.id]
        const widthChanged = width !== widthOf(w, entryOf(w)?.default_layout)
        if (widthChanged || visible !== w.is_visible) {
          await boardApi.updateWidget(dashboard.id, w.id, {
            ...(widthChanged && { layout: { w: width, h: heightOf(w, entryOf(w)?.default_layout) } }),
            ...(visible !== w.is_visible && { is_visible: visible }),
          })
        }
      }
    },
    onSuccess: () => {
      toast.success('Tata letak disimpan')
      setDraft(null)
    },
    onError: (e) => toast.error('Tata letak gagal disimpan', e instanceof Error ? e.message : undefined),
    onSettled: refresh,
  })

  const remove = useMutation({
    mutationFn: (w: DashboardWidget) => boardApi.removeWidget(dashboard!.id, w.id),
    onSuccess: (_, removed) => {
      toast.success('Widget dihapus')
      setRemoving(null)
      setDraft((d) => d && { ...d, order: d.order.filter((id) => id !== removed.id) })
      void refresh()
    },
  })

  const reset = useMutation({
    mutationFn: () => boardApi.applyTemplate(dashboard!.id),
    onSuccess: (response) => {
      client.setQueryData(dashboardKey, response)
      toast.success('Dashboard dikembalikan ke template')
      setResetting(false)
      setDraft(null)
      void refresh()
    },
  })

  const move = (id: string, step: -1 | 1) =>
    setDraft((d) => {
      if (!d) return d
      const next = [...d.order]
      const from = next.indexOf(id)
      const to = from + step
      if (to < 0 || to >= next.length) return d
      ;[next[from], next[to]] = [next[to], next[from]]
      return { ...d, order: next }
    })

  const description = !dashboard
    ? undefined
    : dashboard.dashboard_type === 'asset_board'
      ? dashboard.template
        ? `Template: ${dashboard.template.name} · salinan pribadi Anda`
        : 'Salinan pribadi Anda'
      : `Dashboard kategori ${dashboard.category?.name ?? dashboard.name}, berlaku juga untuk subkategorinya${dashboard.can.edit ? '' : ' · hanya pengelola dashboard yang dapat mengubahnya'}`

  return (
    <>
      <PageHeader
        title="Dashboard Aset"
        description={description}
        actions={
          canEdit && !editing ? (
            <>
              <Button variant="secondary" onClick={startEditing}>
                Atur tata letak
              </Button>
              <Button onClick={() => setAdding(true)}>
                <Plus className="size-4" aria-hidden /> Tambah widget
              </Button>
            </>
          ) : undefined
        }
      />

      {!wide && (
        <div className="mb-4">
          <Alert tone="info" title="Tampilan saja di ponsel">
            Atur tata letak dan tambah widget dilakukan di web pada layar lebar.
          </Alert>
        </div>
      )}

      <div className="mb-4">
        <PillTabs
          label="Dashboard"
          value={tab}
          onChange={(next) => {
            setDraft(null)
            setParams(next ? { kategori: next } : {}, { replace: true })
          }}
          tabs={[{ value: '', label: 'Ringkasan' }, ...categories.map((c) => ({ value: c.category_id, label: c.name }))]}
        />
      </div>

      {editing && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-100">
          <span>Ubah urutan dengan tombol panah, pilih lebar, atau sembunyikan widget. {dashboard?.dashboard_type === 'asset_board' ? 'Perubahan hanya untuk Anda.' : 'Perubahan berlaku untuk semua yang membuka dashboard kategori ini.'}</span>
          <span className="flex flex-wrap gap-2">
            <Button variant="ghost" size="sm" onClick={() => setResetting(true)} disabled={saveLayout.isPending}>
              <RotateCcw className="size-4" aria-hidden /> Kembalikan ke template
            </Button>
            <Button variant="secondary" size="sm" onClick={() => setDraft(null)} disabled={saveLayout.isPending}>
              Batal
            </Button>
            <Button size="sm" onClick={() => saveLayout.mutate()} loading={saveLayout.isPending}>
              Simpan tata letak
            </Button>
          </span>
        </div>
      )}

      {query.isPending ? (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-6 xl:grid-cols-12" role="status" aria-label="Memuat dashboard">
          {[3, 3, 3, 3, 6, 6].map((w, i) => (
            <Skeleton key={i} className={clsx('h-32 rounded-xl', SPAN[w as Width])} />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : shown.length === 0 ? (
        <div className="rounded-xl bg-white p-8 text-center ring-1 ring-slate-200">
          <p className="text-sm text-slate-600">Dashboard ini belum memiliki widget.</p>
          {canEdit && (
            <Button className="mt-3" onClick={() => setAdding(true)}>
              <Plus className="size-4" aria-hidden /> Tambah widget
            </Button>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-6 xl:grid-cols-12">
          {shown.map((w, i) => (
            <WidgetCard
              key={w.id}
              dashboardId={dashboard!.id}
              widget={w}
              entry={entryOf(w)}
              width={draft?.width[w.id] ?? widthOf(w, entryOf(w)?.default_layout)}
              edit={
                editing
                  ? {
                      visible: visibleOf(w),
                      first: i === 0,
                      last: i === shown.length - 1,
                      onUp: () => move(w.id, -1),
                      onDown: () => move(w.id, 1),
                      onWidth: (width) => setDraft((d) => d && { ...d, width: { ...d.width, [w.id]: width } }),
                      onToggle: () => setDraft((d) => d && { ...d, visible: { ...d.visible, [w.id]: !visibleOf(w) } }),
                      onSettings: () => setSettingsFor(w),
                      onRemove: () => setRemoving(w),
                    }
                  : undefined
              }
            />
          ))}
          {editing && (
            <button
              type="button"
              onClick={() => setAdding(true)}
              className="flex min-h-[7.5rem] items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 text-sm font-semibold text-slate-600 hover:border-brand-400 hover:text-brand-700 md:col-span-3 xl:col-span-4"
            >
              <Plus className="size-4" aria-hidden /> Tambah widget
            </button>
          )}
        </div>
      )}

      {dashboard && canEdit && (
        <AddWidgetModal open={adding} onClose={() => setAdding(false)} dashboardId={dashboard.id} catalog={catalog} categoryId={dashboard.category?.id} onAdded={(w) => {
            // While arranging, the new widget joins the draft at the end.
            setDraft((d) => d && { order: [...d.order, w.id], width: { ...d.width, [w.id]: widthOf(w) }, visible: { ...d.visible, [w.id]: true } })
            void refresh()
          }} />
      )}
      {dashboard && settingsFor && (
        <WidgetSettingsModal widget={settingsFor} entry={entryOf(settingsFor)} dashboardId={dashboard.id} categoryId={dashboard.category?.id} onClose={() => setSettingsFor(null)} onSaved={() => void refresh()} />
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Hapus widget?"
        confirmLabel="Hapus widget"
        tone="danger"
        loading={remove.isPending}
        error={remove.error}
        onConfirm={() => removing && remove.mutate(removing)}
        onClose={() => setRemoving(null)}
      >
        <p>Widget “{removing?.title}” dihapus dari dashboard ini. Anda dapat menambahkannya lagi dari katalog.</p>
      </ConfirmDialog>
      <ConfirmDialog
        open={resetting}
        title="Kembalikan ke template?"
        confirmLabel="Kembalikan ke template"
        loading={reset.isPending}
        error={reset.error}
        onConfirm={() => reset.mutate()}
        onClose={() => setResetting(false)}
      >
        <p>Semua widget di dashboard ini diganti dengan salinan baru dari template{dashboard?.template ? ` “${dashboard.template.name}”` : ''}. Perubahan yang Anda buat akan hilang.</p>
      </ConfirmDialog>
    </>
  )
}

interface EditControls {
  visible: boolean
  first: boolean
  last: boolean
  onUp: () => void
  onDown: () => void
  onWidth: (w: Width) => void
  onToggle: () => void
  onSettings: () => void
  onRemove: () => void
}

function WidgetCard({ dashboardId, widget, entry, width, edit }: { dashboardId: string; widget: DashboardWidget; entry: CatalogEntry | undefined; width: Width; edit?: EditControls }) {
  const { profile } = useAuth()
  const data = useQuery({
    queryKey: ['/dashboards', dashboardId, 'widgets', widget.id, 'data', widget.configuration],
    queryFn: () => boardApi.widgetData(dashboardId, widget.id),
    staleTime: 60_000,
    retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
  })
  const height = heightOf(widget, entry?.default_layout)

  return (
    <section aria-label={widget.title} className={clsx('flex flex-col rounded-xl bg-white shadow-card ring-1 ring-slate-200/80', SPAN[width], MIN_HEIGHT[height], edit && !edit.visible && 'opacity-50')}>
      <header className="flex items-start justify-between gap-2 px-4 pb-1 pt-3.5">
        <h2 className="min-w-0 text-[0.9375rem] font-semibold leading-snug text-slate-900">{widget.title}</h2>
        {edit && (
          <span className="flex shrink-0 items-center gap-0.5">
            <IconButton label={`Pengaturan ${widget.title}`} onClick={edit.onSettings}>
              <Settings2 className="size-4" aria-hidden />
            </IconButton>
            <IconButton label={`Hapus ${widget.title}`} onClick={edit.onRemove}>
              <Trash2 className="size-4" aria-hidden />
            </IconButton>
          </span>
        )}
      </header>
      <div className="flex-1 px-4 pb-4 pt-1">
        {data.isPending ? (
          <div className="space-y-2 pt-1" aria-label={`Memuat ${widget.title}`} role="status">
            <Skeleton className="h-6 w-1/2" />
            {height > 1 && <Skeleton className="h-24 w-full" />}
          </div>
        ) : data.isError ? (
          <WidgetError error={data.error} onRetry={() => data.refetch()} />
        ) : (
          // Own boundary per widget (docs/08 §2): a payload that fails to render leaves the rest standing.
          <ModuleBoundary name={widget.title} compact>
            <WidgetBody payload={data.data.data} currency={profile?.organization?.currency} />
          </ModuleBoundary>
        )}
      </div>
      {edit && (
        <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2">
          <Select aria-label={`Lebar ${widget.title}`} value={width} onChange={(e) => edit.onWidth(Number(e.target.value) as Width)} className="min-h-8 w-auto py-1">
            {WIDTHS.map((w) => (
              <option key={w} value={w}>
                Lebar {WIDTH_LABEL[w]}
              </option>
            ))}
          </Select>
          <span className="flex items-center gap-0.5">
            <IconButton label={`Naikkan ${widget.title}`} onClick={edit.onUp} disabled={edit.first}>
              <ArrowUp className="size-4" aria-hidden />
            </IconButton>
            <IconButton label={`Turunkan ${widget.title}`} onClick={edit.onDown} disabled={edit.last}>
              <ArrowDown className="size-4" aria-hidden />
            </IconButton>
            <IconButton label={`${edit.visible ? 'Sembunyikan' : 'Tampilkan'} ${widget.title}`} onClick={edit.onToggle}>
              {edit.visible ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
            </IconButton>
          </span>
        </footer>
      )}
    </section>
  )
}

function WidgetError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const message = error instanceof ApiError ? error.message : 'Data widget tidak dapat dimuat.'
  const requestId = error instanceof ApiError ? error.requestId : null
  return (
    <div className="rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-100">
      <p className="font-semibold">Widget gagal dimuat</p>
      <p className="mt-0.5">{message}</p>
      <p className="mt-1 text-xs">
        {requestId && <>ID permintaan: {requestId} · </>}
        <button type="button" className="font-semibold underline" onClick={onRetry}>
          Coba lagi
        </button>
      </p>
    </div>
  )
}

