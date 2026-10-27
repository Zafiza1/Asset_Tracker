import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api, ApiError, qs } from '../../lib/api/client'
import type {
  AttributeDataType,
  AttributeDefinition,
  AttributeOption,
  AttributeValidation,
  AttributeValue,
  OverridableProperty,
  ReferenceItem,
} from '../../lib/api/types'
import { dataTypeLabel, formatAttributeValue, overridableLabel } from '../../lib/format'
import { describeRules, hasOptions, narrowableKeys, parseValidationValue, validationKeys, validationLabel } from './attributeRules'

interface ValueSpec {
  data_type: AttributeDataType
  options: AttributeOption[] | null
  validation?: AttributeValidation
}

/** Typed input for one attribute value (defaults now; asset attribute values in Phase 4). */
export function AttributeValueInput({ spec, value, onChange, id, disabled }: { spec: ValueSpec; value: AttributeValue; onChange: (v: AttributeValue) => void; id?: string; disabled?: boolean }) {
  const common = { id, disabled }
  switch (spec.data_type) {
    case 'text':
      return <Textarea {...common} rows={3} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)} />
    case 'integer':
    case 'decimal':
      return (
        <Input
          {...common}
          type="number"
          step={spec.data_type === 'integer' ? 1 : 'any'}
          value={value === null || value === undefined ? '' : String(value)}
          onChange={(e) => onChange(e.target.value === '' ? null : spec.data_type === 'integer' ? parseInt(e.target.value, 10) : Number(e.target.value))}
        />
      )
    case 'boolean':
      return (
        <Select {...common} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value === 'true')}>
          <option value="">—</option>
          <option value="true">Ya</option>
          <option value="false">Tidak</option>
        </Select>
      )
    case 'date':
      return <Input {...common} type="date" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)} />
    case 'enum':
      return (
        <Select {...common} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)}>
          <option value="">—</option>
          {spec.options?.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      )
    case 'multi_enum': {
      const selected = Array.isArray(value) ? value : []
      return (
        <div id={id} className="flex flex-wrap gap-3 rounded-md p-2 ring-1 ring-slate-300">
          {spec.options?.map((o) => (
            <label key={o.value} className="flex items-center gap-1.5 text-sm">
              <input
                type="checkbox"
                disabled={disabled}
                className="size-4 rounded border-slate-300"
                checked={selected.includes(o.value)}
                onChange={(e) => {
                  const next = e.target.checked ? [...selected, o.value] : selected.filter((v) => v !== o.value)
                  onChange(next.length ? next : null)
                }}
              />
              {o.label}
            </label>
          ))}
        </div>
      )
    }
    default:
      return <Input {...common} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)} />
  }
}

export function AttributeTable({ attributes, actions }: { attributes: AttributeDefinition[]; actions?: (a: AttributeDefinition) => React.ReactNode }) {
  if (attributes.length === 0) return <p className="p-4 text-sm text-slate-500">Belum ada atribut.</p>
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50 text-left text-slate-600">
          <tr>
            <th className="px-4 py-2.5 font-semibold">Atribut</th>
            <th className="px-4 py-2.5 font-semibold">Tipe</th>
            <th className="hidden px-4 py-2.5 font-semibold md:table-cell">Aturan</th>
            <th className="hidden px-4 py-2.5 font-semibold md:table-cell">Default</th>
            {actions && <th className="px-4 py-2.5 text-right font-semibold">Aksi</th>}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {attributes.map((a) => (
            <tr key={a.id}>
              <td className="px-4 py-3 align-top">
                <p className="font-medium text-slate-900">
                  {a.label}
                  {a.is_required && <span className="text-red-600"> *</span>}
                </p>
                <p className="font-mono text-xs text-slate-500">{a.key}</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {a.is_searchable && <Badge>Dapat dicari</Badge>}
                  {a.is_controlled && <Badge tone="amber">Terkontrol</Badge>}
                  {a.overridable.length > 0 && <Badge tone="blue">Override: {a.overridable.length}</Badge>}
                </div>
              </td>
              <td className="px-4 py-3 align-top text-slate-700">{dataTypeLabel[a.data_type]}</td>
              <td className="hidden px-4 py-3 align-top text-slate-600 md:table-cell">{describeRules(a)}</td>
              <td className="hidden px-4 py-3 align-top text-slate-600 md:table-cell">{formatAttributeValue(a.default_value, a.options)}</td>
              {actions && <td className="px-4 py-3 text-right align-top whitespace-nowrap">{actions(a)}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

interface Draft {
  key: string
  label: string
  help_text: string
  data_type: AttributeDataType
  is_required: boolean
  is_searchable: boolean
  is_controlled: boolean
  unit_id: string
  validation: Partial<Record<keyof AttributeValidation, string>>
  options: AttributeOption[]
  default_value: AttributeValue
  overridable: OverridableProperty[]
  sort_order: string
}

function toDraft(a?: AttributeDefinition): Draft {
  return {
    key: a?.key ?? '',
    label: a?.label ?? '',
    help_text: a?.help_text ?? '',
    data_type: a?.data_type ?? 'string',
    is_required: a?.is_required ?? false,
    is_searchable: a?.is_searchable ?? false,
    is_controlled: a?.is_controlled ?? false,
    unit_id: a?.unit_id ?? '',
    validation: Object.fromEntries(Object.entries(a?.validation ?? {}).map(([k, v]) => [k, String(v)])),
    options: a?.options ?? [],
    default_value: a?.default_value ?? null,
    overridable: a?.overridable ?? [],
    sort_order: a ? String(a.sort_order) : '',
  }
}

/**
 * Create/edit an attribute definition. `generic` adds the platform-only fields
 * (controlled flag and which properties tenants may override).
 */
export function AttributeFormModal({
  attribute,
  generic,
  unitsEndpoint,
  onSubmit,
  onClose,
}: {
  attribute?: AttributeDefinition
  generic: boolean
  unitsEndpoint: string
  onSubmit: (payload: Record<string, unknown>) => Promise<unknown>
  onClose: () => void
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(attribute))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const units = useQuery({
    queryKey: ['options', unitsEndpoint],
    queryFn: async () => (await api.get<{ data: ReferenceItem[] }>(`${unitsEndpoint}${qs({ all: 1, status: 'active' })}`)).data,
    staleTime: 60_000,
  })
  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }))
  const type = draft.data_type

  const submit = async () => {
    const validation: AttributeValidation = {}
    for (const key of validationKeys[type]) {
      const parsed = parseValidationValue(type, key, draft.validation[key] ?? '')
      if (parsed !== undefined) (validation as Record<string, unknown>)[key] = parsed
    }
    const payload: Record<string, unknown> = {
      label: draft.label,
      help_text: draft.help_text || null,
      data_type: type,
      is_required: draft.is_required,
      is_searchable: draft.is_searchable,
      unit_id: draft.unit_id || null,
      validation,
      options: hasOptions(type) ? draft.options : null,
      default_value: draft.default_value,
      ...(draft.sort_order !== '' ? { sort_order: parseInt(draft.sort_order, 10) } : {}),
      ...(attribute ? {} : { key: draft.key }),
      ...(generic ? { is_controlled: draft.is_controlled, overridable: draft.overridable } : {}),
    }
    setSaving(true)
    setErrors({})
    setMessage(null)
    try {
      await onSubmit(payload)
      onClose()
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fields).length > 0) setErrors(e.fields)
      else setMessage(e instanceof ApiError ? e.message : 'Terjadi kesalahan yang tidak terduga.')
    } finally {
      setSaving(false)
    }
  }

  const err = (name: string) => errors[name]?.[0]

  return (
    <Modal
      size="full"
      open
      title={attribute ? `Ubah atribut ${attribute.label}` : 'Tambah atribut'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={saving} onClick={submit}>
            Simpan atribut
          </Button>
        </>
      }
    >
      {message && <Alert tone="error">{message}</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Kunci" required={!attribute} error={err('key')} hint={attribute ? 'Kunci tidak dapat diubah.' : 'huruf_kecil_dengan_garis_bawah'}>
          {(p) => <Input {...p} value={draft.key} disabled={!!attribute} onChange={(e) => set('key', e.target.value)} />}
        </Field>
        <Field label="Label" required error={err('label')}>
          {(p) => <Input {...p} value={draft.label} onChange={(e) => set('label', e.target.value)} />}
        </Field>
        <Field label="Tipe data" required error={err('data_type')}>
          {(p) => (
            <Select
              {...p}
              value={type}
              onChange={(e) => setDraft((d) => ({ ...d, data_type: e.target.value as AttributeDataType, validation: {}, default_value: null, options: hasOptions(e.target.value as AttributeDataType) ? d.options : [] }))}
            >
              {Object.entries(dataTypeLabel).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Satuan" error={err('unit_id')}>
          {(p) => (
            <Select {...p} value={draft.unit_id} onChange={(e) => set('unit_id', e.target.value)}>
              <option value="">— Tanpa satuan —</option>
              {units.data?.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} ({u.symbol})
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
      <Field label="Teks bantuan" error={err('help_text')}>
        {(p) => <Input {...p} value={draft.help_text} onChange={(e) => set('help_text', e.target.value)} />}
      </Field>
      <div className="flex flex-wrap gap-4 text-sm">
        <Checkbox label="Wajib diisi" checked={draft.is_required} onChange={(v) => set('is_required', v)} />
        <Checkbox label="Dapat dicari/difilter" checked={draft.is_searchable} onChange={(v) => set('is_searchable', v)} />
        {generic && <Checkbox label="Terkontrol (diubah lewat transaksi koreksi)" checked={draft.is_controlled} onChange={(v) => set('is_controlled', v)} />}
      </div>

      {validationKeys[type].length > 0 && (
        <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">Validasi</legend>
          {err('validation') && <p className="mb-2 text-sm text-red-600">{errors.validation.join(' ')}</p>}
          <div className="grid gap-3 sm:grid-cols-3">
            {validationKeys[type].map((key) => (
              <Field key={key} label={validationLabel[key]}>
                {(p) => (
                  <Input
                    {...p}
                    type={key === 'pattern' ? 'text' : type === 'date' ? 'date' : 'number'}
                    value={draft.validation[key] ?? ''}
                    onChange={(e) => set('validation', { ...draft.validation, [key]: e.target.value })}
                  />
                )}
              </Field>
            ))}
          </div>
        </fieldset>
      )}

      {hasOptions(type) && <OptionsEditor options={draft.options} onChange={(o) => set('options', o)} error={errors.options?.join(' ')} />}

      <Field label="Nilai default" error={err('default_value')}>
        {(p) => <AttributeValueInput id={p.id} spec={{ data_type: type, options: draft.options }} value={draft.default_value} onChange={(v) => set('default_value', v)} />}
      </Field>

      {generic && (
        <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">Boleh di-override perusahaan</legend>
          {err('overridable') && <p className="mb-2 text-sm text-red-600">{errors.overridable.join(' ')}</p>}
          <div className="grid gap-2 sm:grid-cols-2">
            {(Object.keys(overridableLabel) as OverridableProperty[]).map((prop) => {
              const unavailable = (prop === 'options' && !hasOptions(type)) || (prop === 'validation' && narrowableKeys(type).length === 0)
              return (
                <Checkbox
                  key={prop}
                  label={overridableLabel[prop]}
                  disabled={unavailable}
                  checked={draft.overridable.includes(prop) && !unavailable}
                  onChange={(v) => set('overridable', v ? [...draft.overridable, prop] : draft.overridable.filter((p) => p !== prop))}
                />
              )
            })}
          </div>
        </fieldset>
      )}
      <Field label="Urutan" error={err('sort_order')}>
        {(p) => <Input {...p} type="number" value={draft.sort_order} onChange={(e) => set('sort_order', e.target.value)} />}
      </Field>
    </Modal>
  )
}

function OptionsEditor({ options, onChange, error }: { options: AttributeOption[]; onChange: (o: AttributeOption[]) => void; error?: string }) {
  const update = (i: number, patch: Partial<AttributeOption>) => onChange(options.map((o, j) => (j === i ? { ...o, ...patch } : o)))
  return (
    <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
      <legend className="px-1 text-sm font-semibold text-slate-700">Opsi pilihan</legend>
      {error && <p className="mb-2 text-sm text-red-600">{error}</p>}
      <div className="space-y-2">
        {options.map((o, i) => (
          <div key={i} className="flex gap-2">
            <Input aria-label={`Nilai opsi ${i + 1}`} placeholder="NILAI" value={o.value} onChange={(e) => update(i, { value: e.target.value })} className="font-mono" />
            <Input aria-label={`Label opsi ${i + 1}`} placeholder="Label" value={o.label} onChange={(e) => update(i, { label: e.target.value })} />
            <Button variant="ghost" size="sm" onClick={() => onChange(options.filter((_, j) => j !== i))} aria-label={`Hapus opsi ${i + 1}`}>
              <Trash2 className="size-4" aria-hidden />
            </Button>
          </div>
        ))}
        <Button variant="secondary" size="sm" onClick={() => onChange([...options, { value: '', label: '' }])}>
          <Plus className="size-4" aria-hidden /> Tambah opsi
        </Button>
      </div>
    </fieldset>
  )
}

export function Checkbox({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <label className="flex items-center gap-2 text-sm text-slate-700">
      <input type="checkbox" className="size-4 rounded border-slate-300 disabled:opacity-50" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      <span className={disabled ? 'text-slate-400' : undefined}>{label}</span>
    </label>
  )
}
