import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
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
import { useCategoryAttributes } from './categoryAttributeData'

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

  const sectionLabel: Record<string, string> = {
    basic_information: 'Basic Information',
    location_ownership: 'Location & Ownership',
    status_condition: 'Status & Condition',
    other: 'Other',
  }

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
            {sectionLabel[section] || section}
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
 * The fields of a category in one form: the category's own fields are rows that can be edited, added, and removed, and one "Simpan" saves them all
 * (PUT, all or nothing). Without master.tenant.update the list is read-only.
 */
export function CategoryAttributesModal({ category, canUpdate, onClose }: { category: TenantCategory; canUpdate: boolean; onClose: () => void }) {
  const fields = useCategoryAttributes(category.id)
  const editable = canUpdate && category.status !== 'archived'
  const own = fields.data?.filter((f) => f.category_id === category.id) ?? []
  const defaultOwn = own.filter((f) => f.is_default)
  const customOwn = own.filter((f) => !f.is_default)

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
          <ReadOnlyList fields={own} />
        )}
      </Modal>
    )
  }
  return <AttributeListForm category={category} defaultOwn={defaultOwn} customOwn={customOwn} onClose={onClose} />
}

function ReadOnlyList({ fields }: { fields: CategoryAttribute[] }) {
  if (fields.length === 0) return <p className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Belum ada atribut pada kategori ini.</p>

  const sectionLabel: Record<string, string> = {
    basic_information: 'Basic Information',
    location_ownership: 'Location & Ownership',
    status_condition: 'Status & Condition',
    other: 'Other',
  }

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
          <p className="mb-2 text-sm font-semibold text-slate-700">{sectionLabel[section] || section}</p>
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
}

let nextUid = 1
const newRow = (): Row => ({ uid: nextUid++, label: '', data_type: 'string', is_required: false, options: [] })

function AttributeListForm({ category, defaultOwn, customOwn, onClose }: { category: TenantCategory; defaultOwn: CategoryAttribute[]; customOwn: CategoryAttribute[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [rows, setRows] = useState<Row[]>(() =>
    customOwn.length > 0
      ? customOwn.map((f) => ({ uid: nextUid++, id: f.id, label: f.label, data_type: f.data_type, is_required: f.is_required, options: f.options ?? [] }))
      : [newRow()],
  )
  const [errors, setErrors] = useState<Record<number, Record<string, string>>>({})
  const [message, setMessage] = useState<string | null>(null)

  const update = (uid: number, patch: Partial<Row>) => setRows((current) => current.map((r) => (r.uid === uid ? { ...r, ...patch } : r)))
  // Empty new rows are ignored, so an unused blank row never blocks saving.
  const submitted = rows.filter((r) => r.id || r.label.trim() !== '')
  const removedCount = customOwn.filter((f) => !rows.some((r) => r.id === f.id)).length

  const save = useMutation({
    mutationFn: () =>
      api.put(`/tenant-masters/categories/${category.id}/attributes`, {
        attributes: submitted.map((r) => ({
          ...(r.id ? { id: r.id } : {}),
          label: r.label.trim(),
          data_type: r.data_type,
          is_required: r.is_required,
          options: r.data_type === 'enum' ? r.options.filter((o) => o.label.trim() !== '').map((o) => (o.value ? o : { label: o.label.trim() })) : null,
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

  const sectionLabel: Record<string, string> = {
    basic_information: 'Basic Information',
    location_ownership: 'Location & Ownership',
    status_condition: 'Status & Condition',
    other: 'Other',
  }

  const groupedDefaults = defaultOwn.reduce((acc, attr) => {
    const section = attr.section || 'other'
    if (!acc[section]) acc[section] = []
    acc[section].push(attr)
    return acc
  }, {} as Record<string, CategoryAttribute[]>)

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

      {/* Default Attributes (Read-only) */}
      {defaultOwn.length > 0 && (
        <div className="space-y-4 mb-6">
          <p className="text-sm font-medium text-slate-700">Atribut Default (Read-only)</p>
          {Object.entries(groupedDefaults).map(([section, attrs]) => (
            <div key={section} className="rounded-md bg-slate-50 p-3">
              <p className="mb-2 text-sm font-semibold text-slate-800">{sectionLabel[section] || section}</p>
              <ul className="divide-y divide-slate-200 text-sm">
                {attrs.map((f) => (
                  <li key={f.id} className="flex justify-between px-2 py-1.5">
                    <span className="text-slate-700">
                      {f.label}
                      {f.is_required && <span className="text-red-600"> *</span>}
                    </span>
                    <span className="text-slate-500">{dataTypeLabel[f.data_type]}</span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      {/* Custom Attributes (Editable) */}
      <div className="space-y-3">
        <p className="text-sm font-medium text-slate-700">Atribut Kustom</p>
        {rows.map((row, i) => (
          <AttributeRow
            key={row.uid}
            row={row}
            index={i}
            errors={errors[row.uid] ?? {}}
            onChange={(patch) => update(row.uid, patch)}
            onRemove={() => setRows((current) => current.filter((r) => r.uid !== row.uid))}
          />
        ))}
        {rows.length === 0 && <p className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Tidak ada atribut kustom.</p>}
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
  errors,
  onChange,
  onRemove,
}: {
  row: Row
  index: number
  errors: Record<string, string>
  onChange: (patch: Partial<Row>) => void
  onRemove: () => void
}) {
  const n = index + 1
  return (
    <div className="rounded-md p-3 ring-1 ring-slate-200">
      <div className="grid items-start gap-3 sm:grid-cols-[1fr_12rem_auto_auto]">
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
        <div className="sm:pt-8">
          <Checkbox label="Wajib" checked={row.is_required} onChange={(v) => onChange({ is_required: v })} />
        </div>
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
