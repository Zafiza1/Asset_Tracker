import { useMutation } from '@tanstack/react-query'
import clsx from 'clsx'
import { Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { boardApi, CATALOG_GROUPS, heightOf, WIDTH_LABEL, WIDTHS, widthOf, type CatalogEntry, type DashboardWidget, type Width } from './api'
import { WidgetConfigFields } from './WidgetConfigFields'

/** Catalog entries the member may use: a widget needing a permission they lack is not offered (F-06 §4.1). */
function useUsableCatalog(catalog: CatalogEntry[]): CatalogEntry[] {
  const { can } = useAuth()
  return useMemo(() => catalog.filter((c) => !c.required_permission || can(c.required_permission)), [catalog, can])
}

function defaults(entry: CatalogEntry): Record<string, unknown> {
  return Object.fromEntries(Object.entries(entry.configuration_schema.fields ?? {}).flatMap(([k, f]) => (f.default !== undefined ? [[k, f.default]] : [])))
}

/** Empty values are left out, so the server applies the field's default. */
function clean(config: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(config).filter(([, v]) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0)))
}

function WidthSelect({ value, onChange }: { value: Width; onChange: (w: Width) => void }) {
  return (
    <Field label="Lebar">
      {(p) => (
        <Select {...p} value={value} onChange={(e) => onChange(Number(e.target.value) as Width)} className={`${p.className} max-w-40`}>
          {WIDTHS.map((w) => (
            <option key={w} value={w}>
              {WIDTH_LABEL[w]}
            </option>
          ))}
        </Select>
      )}
    </Field>
  )
}

function fieldErrors(error: unknown): Record<string, string[]> {
  return error instanceof ApiError ? error.fields : {}
}

export function AddWidgetModal({ open, onClose, dashboardId, catalog, categoryId, onAdded }: { open: boolean; onClose: () => void; dashboardId: string; catalog: CatalogEntry[]; categoryId?: string | null; onAdded: (widget: DashboardWidget) => void }) {
  const usable = useUsableCatalog(catalog)
  const [search, setSearch] = useState('')
  const [group, setGroup] = useState('all')
  const [selected, setSelected] = useState<CatalogEntry | null>(null)
  const [title, setTitle] = useState('')
  const [config, setConfig] = useState<Record<string, unknown>>({})
  const [width, setWidth] = useState<Width>(4)

  const term = search.trim().toLowerCase()
  const groupTypes = CATALOG_GROUPS.find((g) => g.key === group)?.types
  const shown = usable.filter((c) => (!groupTypes || groupTypes.includes(c.component_type)) && (!term || `${c.name} ${c.description ?? ''}`.toLowerCase().includes(term)))

  const pick = (entry: CatalogEntry) => {
    setSelected(entry)
    setTitle(entry.name)
    setConfig(defaults(entry))
    setWidth(widthOf({ layout: {} }, entry.default_layout))
  }
  const reset = () => {
    setSelected(null)
    setSearch('')
    setGroup('all')
  }
  const close = () => {
    reset()
    onClose()
  }

  const add = useMutation({
    mutationFn: () =>
      boardApi.addWidget(dashboardId, {
        widget_catalog_id: selected!.id,
        title: title.trim() && title.trim() !== selected!.name ? title.trim() : null,
        configuration: clean(config),
        layout: { w: width, h: heightOf({ layout: {} }, selected!.default_layout) },
      }),
    onSuccess: (response) => {
      toast.success('Widget ditambahkan', 'Widget baru diletakkan di bagian bawah dashboard.')
      onAdded(response.data)
      close()
    },
  })

  return (
    <Modal
      open={open}
      title="Tambah widget"
      onClose={close}
      size="xl"
      footer={
        <>
          <Button variant="secondary" onClick={close} disabled={add.isPending}>
            Batal
          </Button>
          <Button onClick={() => add.mutate()} disabled={!selected} loading={add.isPending}>
            Tambahkan
          </Button>
        </>
      }
    >
      <p className="mb-3 text-sm text-slate-600">Widget yang membutuhkan izin yang tidak Anda miliki tidak ditampilkan.</p>
      {add.error && !Object.keys(fieldErrors(add.error)).length && (
        <div className="mb-3">
          <Alert tone="error" title="Widget gagal ditambahkan">
            {add.error instanceof Error ? add.error.message : null}
          </Alert>
        </div>
      )}
      <div className="grid gap-4 md:grid-cols-[11rem_1fr]">
        <nav aria-label="Kelompok widget" className="flex gap-1 overflow-x-auto md:flex-col">
          {[{ key: 'all', label: 'Semua', types: null }, ...CATALOG_GROUPS].map((g) => {
            const n = usable.filter((c) => !g.types || g.types.includes(c.component_type)).length
            return (
              <button
                key={g.key}
                type="button"
                aria-pressed={group === g.key}
                onClick={() => setGroup(g.key)}
                className={clsx('flex shrink-0 items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm', group === g.key ? 'bg-brand-50 font-semibold text-brand-800' : 'text-slate-600 hover:bg-slate-100')}
              >
                {g.label}
                <span className="text-xs text-slate-500">{n}</span>
              </button>
            )
          })}
        </nav>
        <div className="min-w-0">
          <div className="relative mb-3">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <Input aria-label="Cari widget" placeholder="Cari widget, mis. jatuh tempo, nilai, lokasi" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
          </div>
          <ul className="grid max-h-72 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
            {shown.map((c) => (
              <li key={c.id}>
                <button
                  type="button"
                  aria-pressed={selected?.id === c.id}
                  onClick={() => pick(c)}
                  className={clsx(
                    'h-full w-full rounded-lg p-3 text-left ring-1 transition-colors',
                    selected?.id === c.id ? 'bg-brand-50 ring-2 ring-brand-500' : 'ring-slate-200 hover:bg-slate-50',
                  )}
                >
                  <span className="block text-sm font-semibold text-slate-900">{c.name}</span>
                  {c.description && <span className="mt-0.5 block text-xs text-slate-500">{c.description}</span>}
                </button>
              </li>
            ))}
            {shown.length === 0 && <li className="text-sm text-slate-500">Tidak ada widget yang cocok.</li>}
          </ul>

          {selected && (
            <div className="mt-4 space-y-4 border-t border-slate-100 pt-4">
              <Field label="Judul" error={fieldErrors(add.error).title?.[0]}>
                {(p) => <Input {...p} value={title} maxLength={150} onChange={(e) => setTitle(e.target.value)} />}
              </Field>
              <WidgetConfigFields fields={selected.configuration_schema.fields ?? {}} value={config} onChange={setConfig} categoryId={categoryId} errors={fieldErrors(add.error)} />
              <WidthSelect value={width} onChange={setWidth} />
            </div>
          )}
        </div>
      </div>
    </Modal>
  )
}

export function WidgetSettingsModal({ widget, entry, dashboardId, categoryId, onClose, onSaved }: { widget: DashboardWidget; entry: CatalogEntry | undefined; dashboardId: string; categoryId?: string | null; onClose: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(widget.custom_title ?? widget.title)
  const [config, setConfig] = useState<Record<string, unknown>>(widget.configuration)
  const save = useMutation({
    mutationFn: () =>
      boardApi.updateWidget(dashboardId, widget.id, {
        title: title.trim() && title.trim() !== entry?.name ? title.trim() : null,
        configuration: clean(config),
      }),
    onSuccess: () => {
      toast.success('Pengaturan widget disimpan')
      onSaved()
      onClose()
    },
  })

  return (
    <Modal
      open
      title={`Pengaturan: ${widget.title}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={save.isPending}>
            Batal
          </Button>
          <Button onClick={() => save.mutate()} loading={save.isPending}>
            Simpan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {save.error && !Object.keys(fieldErrors(save.error)).length && (
          <Alert tone="error" title="Pengaturan gagal disimpan">
            {save.error instanceof Error ? save.error.message : null}
          </Alert>
        )}
        <Field label="Judul" error={fieldErrors(save.error).title?.[0]}>
          {(p) => <Input {...p} value={title} maxLength={150} onChange={(e) => setTitle(e.target.value)} />}
        </Field>
        <WidgetConfigFields fields={entry?.configuration_schema.fields ?? {}} value={config} onChange={setConfig} categoryId={categoryId} errors={fieldErrors(save.error)} />
      </div>
    </Modal>
  )
}
