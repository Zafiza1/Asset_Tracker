import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api, ApiError } from '../../lib/api/client'
import type { AttributeOption, AttributeValue, CategoryAttribute, CategoryAttributeType, TenantCategory } from '../../lib/api/types'
import { dataTypeLabel } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { AttributeValueInput, Checkbox } from './attributes'
import { SECTION_LABELS, useCategoryAttributes } from './categoryAttributeData'

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
          <ReadOnlyList fields={[...inherited, ...own]} />
        )}
      </Modal>
    )
  }
  return <AttributeListForm category={category} inherited={inherited} own={own} onClose={onClose} />
}

function ReadOnlyList({ fields }: { fields: CategoryAttribute[] }) {
  if (fields.length === 0) return <p className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Belum ada atribut pada kategori ini.</p>

  const groupedFields = fields.reduce((acc, f) => {
    const section = f.section || 'other'
    if (!acc[section]) acc[section] = []
    acc[section].push(f)
    return acc
  }, {} as Record<string, CategoryAttribute[]>)

  return (
    <div className="space-y-4">
      {Object.entries(groupedFields).map(([section, sectionFields]) => (
        <div key={section}>
          <p className="mb-2 text-sm font-semibold text-slate-700">{SECTION_LABELS[section] || section}</p>
          <ul className="divide-y divide-slate-100 rounded-md text-sm ring-1 ring-slate-200">
            {sectionFields.map((f) => (
              <li key={f.id} className="flex flex-wrap justify-between gap-2 px-4 py-2.5">
                <span className="font-medium text-slate-900">
                  {f.label}
                  {f.is_required && <span className="text-red-600"> *</span>}
                  {f.is_default && <span className="ml-1 text-xs text-slate-500">(default)</span>}
                </span>
                <span className="text-slate-600">
                  {dataTypeLabel[f.data_type]}
                  {f.options?.length ? `: ${f.options.map((o) => o.label).join(', ')}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </div>
      ))}
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

  const update = (uid: number, patch: Partial<Row>) => setRows((current) => current.map((r) => (r.uid === uid ? { ...r, ...patch } : r)))

  const move = (index: number, to: number) =>
    setRows((current) => {
      if (to < 0 || to >= current.length) return current
      const next = [...current]
      ;[next[index], next[to]] = [next[to], next[index]]
      return next
    })

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
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['category-attributes'] }),
        queryClient.invalidateQueries({ queryKey: ['/tenant-masters/categories'] }),
      ])
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
      {message && <Alert tone="error">{message}</Alert>}

      {inherited.length > 0 && (
        <div className="mb-6 space-y-2">
          <p className="text-sm font-medium text-slate-700">Atribut dari kategori induk (hanya baca, urutan diatur di kategori induk)</p>
          <ReadOnlyList fields={inherited} />
        </div>
      )}

      <div className="space-y-3">
        <p className="text-sm font-medium text-slate-700">Atribut kategori ini</p>
        {rows.map((row, i) => (
          <AttributeRow
            key={row.uid}
            row={row}
            index={i}
            total={rows.length}
            errors={errors[row.uid] ?? {}}
            onChange={(patch) => update(row.uid, patch)}
            onRemove={() => setRows((current) => current.filter((r) => r.uid !== row.uid))}
            onMoveUp={() => move(i, i - 1)}
            onMoveDown={() => move(i, i + 1)}
          />
        ))}
        {rows.length === 0 && <p className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Tidak ada atribut.</p>}
        <Button variant="secondary" onClick={() => setRows((current) => [...current, newRow()])}>
          <Plus className="size-4" aria-hidden /> Tambah atribut kustom
        </Button>
      </div>
    </Modal>
  )
}

function AttributeRow({
  row,
  index,
  total,
  errors,
  onChange,
  onRemove,
  onMoveUp,
  onMoveDown,
}: {
  row: Row
  index: number
  total: number
  errors: Record<string, string>
  onChange: (patch: Partial<Row>) => void
  onRemove: () => void
  onMoveUp: () => void
  onMoveDown: () => void
}) {
  const n = index + 1
  const moveButtons = (
    <div className="flex gap-1">
      <Button variant="ghost" size="sm" onClick={onMoveUp} disabled={index === 0} aria-label={`Pindah atribut ${n} ke atas`} title="Pindah ke atas">
        <ArrowUp className="size-4" aria-hidden />
      </Button>
      <Button variant="ghost" size="sm" onClick={onMoveDown} disabled={index === total - 1} aria-label={`Pindah atribut ${n} ke bawah`} title="Pindah ke bawah">
        <ArrowDown className="size-4" aria-hidden />
      </Button>
    </div>
  )

  if (row.is_default) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
        <div className="text-sm">
          <span className="font-medium text-slate-900">
            {row.label}
            {row.is_required && <span className="text-red-600"> *</span>}
          </span>
          <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-600">Default</span>
          <p className="text-slate-600">
            {dataTypeLabel[row.data_type]}
            {row.options.length > 0 ? `: ${row.options.map((o) => o.label).join(', ')}` : ''}
            {row.section ? ` · ${SECTION_LABELS[row.section] ?? row.section}` : ''}
          </p>
        </div>
        {moveButtons}
      </div>
    )
  }

  return (
    <div className="rounded-md p-3 ring-1 ring-slate-200">
      <div className="grid items-start gap-3 sm:grid-cols-[1fr_12rem_12rem_auto_auto_auto]">
        <Field label={`Nama atribut ${n}`} required error={errors.label}>
          {(p) => <Input {...p} value={row.label} maxLength={100} placeholder="mis. Nomor Polisi" onChange={(e) => onChange({ label: e.target.value })} />}
        </Field>
        <Field label={`Tipe isian ${n}`} error={errors.data_type} hint={row.id ? 'Tidak dapat diubah.' : undefined}>
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
        </Field>
        <Field label={`Kelompok ${n}`} error={errors.section}>
          {(p) => (
            <Select {...p} value={row.section ?? ''} onChange={(e) => onChange({ section: e.target.value || null })}>
              <option value="">{SECTION_LABELS.other}</option>
              <option value="basic_information">{SECTION_LABELS.basic_information}</option>
              <option value="location_ownership">{SECTION_LABELS.location_ownership}</option>
              <option value="status_condition">{SECTION_LABELS.status_condition}</option>
            </Select>
          )}
        </Field>
        <div className="sm:pt-8">
          <Checkbox label="Wajib" checked={row.is_required} onChange={(v) => onChange({ is_required: v })} />
        </div>
        <div className="sm:pt-6">{moveButtons}</div>
        <div className="sm:pt-6">
          <Button variant="ghost" size="sm" onClick={onRemove} aria-label={`Hapus atribut ${n}`} title="Hapus atribut">
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
    <div className="mt-3 border-t border-slate-100 pt-3">
      <p className="mb-2 text-sm font-medium text-slate-700">Daftar pilihan</p>
      {error && <p className="mb-2 text-sm text-red-600">{error}</p>}
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((o, i) => (
          <div key={i} className="flex gap-2">
            <Input
              aria-label={`Pilihan ${i + 1}`}
              placeholder={`Pilihan ${i + 1}`}
              value={o.label}
              maxLength={100}
              onChange={(e) => onChange(options.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))}
            />
            <Button variant="ghost" size="sm" onClick={() => onChange(options.filter((_, j) => j !== i))} aria-label={`Hapus pilihan ${i + 1}`}>
              <Trash2 className="size-4" aria-hidden />
            </Button>
          </div>
        ))}
      </div>
      <Button className="mt-2" variant="secondary" size="sm" onClick={() => onChange([...options, { value: '', label: '' }])}>
        <Plus className="size-4" aria-hidden /> Tambah pilihan
      </Button>
    </div>
  )
}
