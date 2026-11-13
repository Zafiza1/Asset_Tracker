import { useMutation, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { GripVertical, Info, Lock, Plus, Trash2, X } from 'lucide-react'
import { useId, useState, type DragEvent, type ReactNode } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Alert, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api, ApiError } from '../../lib/api/client'
import type { AttributeOption, AttributeValue, CategoryAttribute, CategoryAttributeType, TenantCategory } from '../../lib/api/types'
import { dataTypeLabel } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { AttributeValueInput } from './attributes'
import { SECTION_LABELS, useCategoryAttributes } from './categoryAttributeData'
import { toast } from '../../components/ui/toast'

/** Data types offered for category fields (mirrors CategoryAttributeService::TYPES). */
const TYPES: CategoryAttributeType[] = ['string', 'text', 'integer', 'decimal', 'date', 'boolean', 'enum']

/** Inputs for the category fields of an asset. Values are keyed by attribute key. */
export function AttributeFields({
  fields,
  values,
  errors,
  onChange,
}: {
  fields: CategoryAttribute[]
  values: Record<string, AttributeValue>
  errors: Record<string, string>
  onChange: (key: string, value: AttributeValue) => void
}) {
  if (fields.length === 0) return null

  // Group fields by section
  const groupedFields = fields.reduce((acc, f) => {
    const section = f.section || 'other'
    if (!acc[section]) acc[section] = []
    acc[section].push(f)
    return acc
  }, {} as Record<string, CategoryAttribute[]>)

  return (
    <div className="space-y-4">
      {Object.entries(groupedFields).map(([section, sectionFields]) => (
        <fieldset key={section} className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">
            {SECTION_LABELS[section] || section}
          </legend>
          <div className="grid gap-4 sm:grid-cols-2">
            {sectionFields.map((f) => (
              <div key={f.key} className={f.data_type === 'text' ? 'sm:col-span-2' : undefined}>
                <Field label={f.label} required={f.is_required} error={errors[f.key]}>
                  {(p) => <AttributeValueInput id={p.id} spec={f} value={values[f.key] ?? null} onChange={(v) => onChange(f.key, v)} />}
                </Field>
              </div>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  )
}

/**
 * The fields of a category in one form: the category's own fields are rows that can be edited, added, removed and reordered, and one "Simpan"
 * saves them all (PUT, all or nothing). Default fields can only be reordered. Without master.tenant.update the list is read-only.
 */
export function CategoryAttributesModal({ category, canUpdate, onClose }: { category: TenantCategory; canUpdate: boolean; onClose: () => void }) {
  const fields = useCategoryAttributes(category.id)
  const editable = canUpdate && category.status !== 'archived'
  // Default fields belong to the top-level category; subcategories inherit them (and the parents' own fields) read-only.
  const inherited = fields.data?.filter((f) => f.category_id !== category.id) ?? []
  const own = fields.data?.filter((f) => f.category_id === category.id) ?? []

  if (fields.isLoading || fields.isError || !editable) {
    return (
      <Modal
        open
        size="full"
        title={`Atribut ${category.name}`}
        onClose={onClose}
        footer={
          <Button variant="secondary" onClick={onClose}>
            Tutup
          </Button>
        }
      >
        {fields.isLoading ? (
          <Spinner />
        ) : fields.isError ? (
          <Alert tone="error">{errorMessage(fields.error)}</Alert>
        ) : (
          <ReadOnlyList fields={[...inherited, ...own]} showOrigin={inherited.length > 0} />
        )}
      </Modal>
    )
  }
  return <AttributeListForm category={category} inherited={inherited} own={own} onClose={onClose} />
}

/** Column layout shared by the header and every row of the attribute table (stacked below md). */
const GRID = 'md:grid md:grid-cols-[3.5rem_minmax(0,1fr)_11rem_12rem_4.5rem_2.75rem] md:items-start md:gap-3'

const sectionLabel = (section: string | null | undefined) => SECTION_LABELS[section || 'other'] ?? section

/** Short explanation of a data type, so users know what the asset form will show. */
const TYPE_HINTS: Record<string, string> = {
  string: 'Satu baris teks, mis. nomor seri.',
  text: 'Beberapa baris teks, mis. catatan.',
  integer: 'Angka tanpa koma, mis. jumlah kursi.',
  decimal: 'Angka dengan koma, mis. berat (kg).',
  date: 'Pemilih tanggal.',
  boolean: 'Kotak centang Ya/Tidak.',
  enum: 'Daftar pilihan yang Anda tentukan.',
  multi_enum: 'Boleh memilih lebih dari satu.',
}

function TypeText({ type, options }: { type: string; options?: AttributeOption[] | null }) {
  return (
    <span className="text-slate-700">
      {dataTypeLabel[type] ?? type}
      {options && options.length > 0 && <span className="mt-0.5 block text-xs text-slate-500">{options.map((o) => o.label).join(', ')}</span>}
    </span>
  )
}

/** A small caption shown above each value only when the table is stacked (below md), where the column header is hidden. */
function MobileCaption({ children }: { children: ReactNode }) {
  return <span className="mb-0.5 block text-xs font-medium text-slate-500 md:hidden">{children}</span>
}

function SectionHeading({ title, description, count }: { title: string; description?: string; count?: number }) {
  return (
    <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
      <div>
        <h3 className="text-sm font-semibold text-slate-900">
          {title}
          {count !== undefined && <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{count}</span>}
        </h3>
        {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
      </div>
    </div>
  )
}

/** Read-only table of fields, grouped by section. `showOrigin` adds the category each field comes from. */
function ReadOnlyList({ fields, showOrigin = false }: { fields: CategoryAttribute[]; showOrigin?: boolean }) {
  if (fields.length === 0) return <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Belum ada atribut pada kategori ini.</p>

  const grouped = fields.reduce(
    (acc, f) => {
      const section = f.section || 'other'
      ;(acc[section] ??= []).push(f)
      return acc
    },
    {} as Record<string, CategoryAttribute[]>,
  )

  return (
    <div className="overflow-hidden rounded-lg ring-1 ring-slate-200">
      <table className="w-full text-left text-sm">
        <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-4 py-2.5">Nama atribut</th>
            <th className="px-4 py-2.5">Tipe isian</th>
            <th className="hidden px-4 py-2.5 sm:table-cell">Wajib</th>
            {showOrigin && <th className="hidden px-4 py-2.5 md:table-cell">Asal kategori</th>}
          </tr>
        </thead>
        {Object.entries(grouped).map(([section, sectionFields]) => (
          <tbody key={section} className="divide-y divide-slate-100 border-t border-slate-200">
            <tr className="bg-slate-50/60">
              <th colSpan={showOrigin ? 4 : 3} scope="colgroup" className="px-4 py-1.5 text-xs font-semibold text-brand-700">
                {sectionLabel(section)}
              </th>
            </tr>
            {sectionFields.map((f) => (
              <tr key={f.id} className="align-top">
                <td className="px-4 py-2.5">
                  <span className="font-medium text-slate-900">{f.label}</span>
                  {f.is_required && <span className="text-red-600" title="Wajib diisi"> *</span>}
                  {f.is_default && (
                    <span className="ml-2 align-middle">
                      <Badge>Bawaan</Badge>
                    </span>
                  )}
                </td>
                <td className="px-4 py-2.5">
                  <TypeText type={f.data_type} options={f.options} />
                </td>
                <td className="hidden px-4 py-2.5 text-slate-700 sm:table-cell">{f.is_required ? 'Ya' : <span className="text-slate-400">Tidak</span>}</td>
                {showOrigin && <td className="hidden px-4 py-2.5 text-slate-600 md:table-cell">{f.category?.name ?? '—'}</td>}
              </tr>
            ))}
          </tbody>
        ))}
      </table>
    </div>
  )
}

interface Row {
  /** Stable React key; also maps server errors back to the row. */
  uid: number
  id?: string
  label: string
  data_type: CategoryAttributeType
  is_required: boolean
  /** A new option has an empty value; the server derives it from the label. */
  options: AttributeOption[]
  /** Default fields can only be moved; the server ignores the rest of their row. */
  is_default: boolean
  section: string | null
}

let nextUid = 1
const newRow = (): Row => ({ uid: nextUid++, label: '', data_type: 'string', is_required: false, options: [], is_default: false, section: null })

function AttributeListForm({ category, inherited, own, onClose }: { category: TenantCategory; inherited: CategoryAttribute[]; own: CategoryAttribute[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  // Own fields (defaults and custom) in their saved order, plus a blank row when there is no custom field yet.
  const [rows, setRows] = useState<Row[]>(() => {
    const existing = own.map((f) => ({
      uid: nextUid++,
      id: f.id,
      label: f.label,
      data_type: f.data_type,
      is_required: f.is_required,
      options: f.options ?? [],
      is_default: f.is_default,
      section: f.section ?? null,
    }))
    return own.some((f) => !f.is_default) ? existing : [...existing, newRow()]
  })
  const [errors, setErrors] = useState<Record<number, Record<string, string>>>({})
  const [message, setMessage] = useState<string | null>(null)
  // Only a row whose grip is pressed is draggable, so text in the row's inputs can still be selected with the mouse.
  const [armed, setArmed] = useState<number | null>(null)
  const [dragging, setDragging] = useState<number | null>(null)

  const update = (uid: number, patch: Partial<Row>) => setRows((current) => current.map((r) => (r.uid === uid ? { ...r, ...patch } : r)))

  const move = (from: number, to: number) =>
    setRows((current) => {
      if (from === to || to < 0 || to >= current.length) return current
      const next = [...current]
      next.splice(to, 0, ...next.splice(from, 1))
      return next
    })

  // Rows reorder live while dragging: the dragged row takes the place of the row under the pointer once it crosses that row's middle.
  const dragOver = (e: DragEvent<HTMLDivElement>, index: number) => {
    if (dragging === null) return
    e.preventDefault()
    const from = rows.findIndex((r) => r.uid === dragging)
    if (from === index) return
    const rect = e.currentTarget.getBoundingClientRect()
    const below = e.clientY > rect.top + rect.height / 2
    if ((from < index && below) || (from > index && !below)) move(from, index)
  }

  // Empty new rows are ignored, so an unused blank row never blocks saving.
  const submitted = rows.filter((r) => r.id || r.label.trim() !== '')
  const removedCount = own.filter((f) => !f.is_default && !rows.some((r) => r.id === f.id)).length

  const save = useMutation({
    mutationFn: () =>
      api.put(`/tenant-masters/categories/${category.id}/attributes`, {
        attributes: submitted.map((r) => ({
          ...(r.id ? { id: r.id } : {}),
          label: r.label.trim(),
          data_type: r.data_type,
          is_required: r.is_required,
          options: r.data_type === 'enum' ? r.options.filter((o) => o.label.trim() !== '').map((o) => (o.value ? o : { label: o.label.trim() })) : null,
          section: r.section || null,
        })),
      }),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['category-attributes'] }),
        queryClient.invalidateQueries({ queryKey: ['/tenant-masters/categories'] }),
      ])
      toast.saved(response, 'Atribut kategori disimpan', category.name)
      onClose()
    },
    onError: (e) => {
      // Server errors are keyed attributes.{index}.{field}, index into the submitted rows.
      const byRow: Record<number, Record<string, string>> = {}
      if (e instanceof ApiError) {
        for (const [path, messages] of Object.entries(e.fields)) {
          const match = /^attributes\.(\d+)\.(\w+)/.exec(path)
          const row = match ? submitted[Number(match[1])] : undefined
          if (match && row) byRow[row.uid] = { ...byRow[row.uid], [match[2]]: messages[0] }
        }
      }
      setErrors(byRow)
      setMessage(Object.keys(byRow).length > 0 ? 'Periksa kembali isian yang ditandai.' : errorMessage(e))
    },
  })

  const defaultCount = rows.filter((r) => r.is_default).length
  const customCount = rows.length - defaultCount
  const addRow = () => setRows((current) => [...current, newRow()])

  return (
    <Modal
      open
      size="full"
      title={`Atribut ${category.name}`}
      onClose={onClose}
      footer={
        <>
          {removedCount > 0 && <p className="mr-auto self-center text-sm text-amber-700">{removedCount} atribut kustom akan dihapus saat disimpan.</p>}
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={save.isPending} onClick={() => (setMessage(null), setErrors({}), save.mutate())}>
            Simpan
          </Button>
        </>
      }
    >
      <div className="space-y-6">
        <div className="rounded-lg bg-brand-50/60 p-4 text-sm text-slate-700 ring-1 ring-inset ring-brand-600/15">
          <p className="flex items-center gap-2 font-semibold text-slate-900">
            <Info className="size-4 text-brand-600" aria-hidden /> Apa itu atribut?
          </p>
          <p className="mt-1">
            Atribut adalah isian tambahan yang muncul di formulir aset kategori <strong>{category.name}</strong>, dengan urutan seperti di daftar ini.
          </p>
          <ul className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-600">
            <li className="flex items-center gap-1.5">
              <GripVertical className="size-3.5" aria-hidden /> Seret untuk mengubah urutan
            </li>
            <li className="flex items-center gap-1.5">
              <Lock className="size-3.5" aria-hidden /> Bawaan: hanya bisa dipindah
            </li>
            <li>
              <span className="font-semibold text-red-600">*</span> Wajib diisi saat membuat aset
            </li>
          </ul>
        </div>

        {message && <Alert tone="error">{message}</Alert>}

        {inherited.length > 0 && (
          <section>
            <SectionHeading title="Atribut dari kategori induk" description="Hanya baca. Ubah atau atur urutannya di kategori induk." count={inherited.length} />
            <ReadOnlyList fields={inherited} showOrigin />
          </section>
        )}

        <section>
          <SectionHeading
            title="Atribut kategori ini"
            description={`${defaultCount > 0 ? `${defaultCount} bawaan · ` : ''}${customCount} kustom`}
            count={rows.length}
          />
          <div className="overflow-hidden rounded-lg ring-1 ring-slate-200">
            <div className={clsx(GRID, 'hidden bg-slate-50 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 md:grid')} aria-hidden>
              <span>Urutan</span>
              <span>Nama atribut</span>
              <span>Tipe isian</span>
              <span>Kelompok</span>
              <span className="text-center">Wajib</span>
              <span />
            </div>
            <div className="divide-y divide-slate-100">
              {rows.map((row, i) => (
                <div
                  key={row.uid}
                  draggable={armed === row.uid}
                  onDragStart={(e) => {
                    e.dataTransfer.effectAllowed = 'move'
                    e.dataTransfer.setData('text/plain', String(row.uid))
                    setDragging(row.uid)
                  }}
                  onDragOver={(e) => dragOver(e, i)}
                  onDrop={(e) => e.preventDefault()}
                  onDragEnd={() => (setDragging(null), setArmed(null))}
                  className={clsx('transition-opacity', dragging === row.uid && 'bg-brand-50 opacity-50')}
                >
                  <AttributeRow
                    row={row}
                    index={i}
                    errors={errors[row.uid] ?? {}}
                    onChange={(patch) => update(row.uid, patch)}
                    onRemove={() => setRows((current) => current.filter((r) => r.uid !== row.uid))}
                    handle={<DragHandle n={i + 1} onArm={() => setArmed(row.uid)} onDisarm={() => setArmed(null)} onMove={(by) => move(i, i + by)} />}
                  />
                </div>
              ))}
              {rows.length === 0 && <p className="p-6 text-center text-sm text-slate-500">Tidak ada atribut. Tambahkan atribut kustom di bawah.</p>}
            </div>
            <button
              type="button"
              onClick={addRow}
              className="flex w-full items-center justify-center gap-2 border-t border-dashed border-slate-300 bg-white px-3 py-3 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600"
            >
              <Plus className="size-4" aria-hidden /> Tambah atribut kustom
            </button>
          </div>
        </section>
      </div>
    </Modal>
  )
}

/** Grip to drag a row by, with the row's position; with focus, the arrow keys move the row up or down. */
function DragHandle({ n, onArm, onDisarm, onMove }: { n: number; onArm: () => void; onDisarm: () => void; onMove: (by: -1 | 1) => void }) {
  return (
    <div className="flex items-center gap-1">
      <Button
        variant="ghost"
        size="sm"
        className="cursor-grab px-1.5 text-slate-400 hover:text-slate-700 active:cursor-grabbing"
        aria-label={`Ubah urutan atribut ${n}`}
        title="Seret untuk mengubah urutan (atau tekan panah atas/bawah)"
        onPointerDown={onArm}
        onPointerUp={onDisarm}
        onKeyDown={(e) => {
          if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return
          e.preventDefault()
          onMove(e.key === 'ArrowUp' ? -1 : 1)
        }}
      >
        <GripVertical className="size-4" aria-hidden />
      </Button>
      <span className="w-5 text-center text-xs font-semibold tabular-nums text-slate-500" aria-hidden>
        {n}
      </span>
    </div>
  )
}

/** One table cell with its own label: visible as a caption when stacked, hidden behind the column header from md up. */
function Cell({ label, n, error, hint, children }: { label: string; n: number; error?: string; hint?: string; children: (p: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string }) => ReactNode }) {
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined
  return (
    <div className="min-w-0">
      <label htmlFor={id} className="mb-1 block text-xs font-medium text-slate-500 md:sr-only">
        {label}
        <span className="sr-only"> {n}</span>
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
      {error ? (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-700">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

function AttributeRow({
  row,
  index,
  errors,
  onChange,
  onRemove,
  handle,
}: {
  row: Row
  index: number
  errors: Record<string, string>
  onChange: (patch: Partial<Row>) => void
  onRemove: () => void
  handle: ReactNode
}) {
  const n = index + 1

  if (row.is_default) {
    return (
      <div className={clsx(GRID, 'flex flex-wrap items-center gap-x-3 gap-y-2 bg-slate-50/70 px-3 py-2.5 text-sm')}>
        <div>{handle}</div>
        <div className="min-w-0 md:pt-1.5">
          <span className="font-medium text-slate-900">{row.label}</span>
          {row.is_required && <span className="text-red-600" title="Wajib diisi"> *</span>}
          <span className="ml-2 align-middle">
            <Badge>Bawaan</Badge>
          </span>
        </div>
        <div className="md:pt-1.5">
          <MobileCaption>Tipe isian</MobileCaption>
          <TypeText type={row.data_type} options={row.options} />
        </div>
        <div className="text-slate-700 md:pt-1.5">
          <MobileCaption>Kelompok</MobileCaption>
          {sectionLabel(row.section)}
        </div>
        <div className="text-slate-700 md:pt-1.5 md:text-center">
          <MobileCaption>Wajib</MobileCaption>
          {row.is_required ? 'Ya' : <span className="text-slate-400">Tidak</span>}
        </div>
        <div className="flex justify-center md:pt-1.5" title="Atribut bawaan tidak dapat diubah atau dihapus">
          <Lock className="size-4 text-slate-400" aria-hidden />
        </div>
      </div>
    )
  }

  return (
    <div className="px-3 py-3">
      <div className={clsx(GRID, 'grid grid-cols-[auto_minmax(0,1fr)_auto] gap-3')}>
        <div className="md:pt-1">{handle}</div>
        <div className="col-span-2 md:col-span-1">
          <Cell label="Nama atribut" n={n} error={errors.label}>
            {(p) => (
              <Input
                {...p}
                value={row.label}
                maxLength={100}
                placeholder="mis. Nomor Polisi"
                className={clsx(errors.label && 'ring-red-500')}
                onChange={(e) => onChange({ label: e.target.value })}
              />
            )}
          </Cell>
        </div>
        <div className="col-span-3 md:col-span-1">
          <Cell label="Tipe isian" n={n} error={errors.data_type} hint={row.id ? 'Tidak dapat diubah setelah disimpan.' : TYPE_HINTS[row.data_type]}>
            {(p) => (
              <Select
                {...p}
                value={row.data_type}
                disabled={!!row.id}
                onChange={(e) => {
                  const type = e.target.value as CategoryAttributeType
                  onChange({ data_type: type, options: type === 'enum' && row.options.length === 0 ? [{ value: '', label: '' }] : row.options })
                }}
              >
                {TYPES.map((t) => (
                  <option key={t} value={t}>
                    {dataTypeLabel[t]}
                  </option>
                ))}
              </Select>
            )}
          </Cell>
        </div>
        <div className="col-span-3 md:col-span-1">
          <Cell label="Kelompok" n={n} error={errors.section}>
            {(p) => (
              <Select {...p} value={row.section ?? ''} onChange={(e) => onChange({ section: e.target.value || null })}>
                <option value="">{SECTION_LABELS.other}</option>
                <option value="basic_information">{SECTION_LABELS.basic_information}</option>
                <option value="location_ownership">{SECTION_LABELS.location_ownership}</option>
                <option value="status_condition">{SECTION_LABELS.status_condition}</option>
                <option value="specific_attributes">{SECTION_LABELS.specific_attributes}</option>
              </Select>
            )}
          </Cell>
        </div>
        <label className="col-span-2 flex items-center gap-2 text-sm text-slate-700 md:col-span-1 md:h-10 md:justify-center">
          <input type="checkbox" className="size-4 rounded border-slate-300" checked={row.is_required} onChange={(e) => onChange({ is_required: e.target.checked })} />
          <span className="md:sr-only">Wajib diisi</span>
        </label>
        <div className="flex justify-end md:justify-center md:pt-1">
          <Button variant="ghost" size="sm" className="text-slate-400 hover:bg-red-50 hover:text-red-600" onClick={onRemove} aria-label={`Hapus atribut ${n}`} title="Hapus atribut">
            <Trash2 className="size-4" aria-hidden />
          </Button>
        </div>
      </div>
      {row.data_type === 'enum' && <OptionList options={row.options} onChange={(options) => onChange({ options })} error={errors.options} />}
      {errors.id && <p className="mt-1 text-sm text-red-600">{errors.id}</p>}
    </div>
  )
}

function OptionList({ options, onChange, error }: { options: AttributeOption[]; onChange: (o: AttributeOption[]) => void; error?: string }) {
  return (
    <div className="mt-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200 md:ml-[4.25rem]">
      <p className="text-xs font-semibold text-slate-700">Daftar pilihan</p>
      <p className="mb-2 text-xs text-slate-500">Pengguna memilih salah satu dari daftar ini saat mengisi aset.</p>
      {error && <p className="mb-2 text-xs text-red-700">{error}</p>}
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {options.map((o, i) => (
          <div key={i} className="flex items-center gap-1.5">
            <span className="w-5 shrink-0 text-right text-xs tabular-nums text-slate-400" aria-hidden>
              {i + 1}.
            </span>
            <Input
              aria-label={`Pilihan ${i + 1}`}
              placeholder={`Pilihan ${i + 1}`}
              value={o.label}
              maxLength={100}
              onChange={(e) => onChange(options.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))}
            />
            <Button
              variant="ghost"
              size="sm"
              className="text-slate-400 hover:bg-red-50 hover:text-red-600"
              onClick={() => onChange(options.filter((_, j) => j !== i))}
              aria-label={`Hapus pilihan ${i + 1}`}
              title="Hapus pilihan"
            >
              <X className="size-4" aria-hidden />
            </Button>
          </div>
        ))}
      </div>
      <Button className="mt-2" variant="ghost" size="sm" onClick={() => onChange([...options, { value: '', label: '' }])}>
        <Plus className="size-4" aria-hidden /> Tambah pilihan
      </Button>
    </div>
  )
}
