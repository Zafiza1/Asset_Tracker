import { useQuery } from '@tanstack/react-query'
import { Paperclip, X } from 'lucide-react'
import { useState } from 'react'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { errorMessage } from '../../lib/forms'
import { toOptions, useOptions } from '../master/options'
import { attachmentAccept, fileSize, typesApi, type AttachmentKind, type AttachmentSummary, type TypeField } from './api'
import { localToday } from '../transactions/api'
import type { FieldValues } from './values'



interface Props {
  typeId: string
  fields: TypeField[]
  values: FieldValues
  onChange: (key: string, value: unknown) => void
  /** API errors keyed like `field_values.<key>`. */
  errors?: Record<string, string[]>
  /** Keys required here (a flow step); default: each field's own is_required. */
  required?: string[]
}

/**
 * Inputs for the fields of an organization's transaction type (F-07 §3.2), shaped by each
 * field's type and settings. The API validates the values again.
 */
export function CustomFieldInputs({ typeId, fields, values, onChange, errors = {}, required }: Props) {
  const locations = toOptions(useOptions('/locations', {}, fields.some((f) => f.data_type === 'location')).data)
  const err = (key: string) => errors[`field_values.${key}`]?.[0] ?? errors[`field_values.${key}.name`]?.[0]
  const isRequired = (f: TypeField) => (required ? required.includes(f.key) : f.is_required)

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {fields.map((f) => {
        const value = values[f.key]
        const s = f.settings ?? {}
        const wide = f.data_type === 'textarea' || f.data_type === 'multiselect' || f.data_type === 'counterparty' || f.data_type === 'attachment'
        const common = { label: f.label, required: isRequired(f), error: err(f.key) }
        let control: React.ReactNode
        switch (f.data_type) {
          case 'text':
            control = <Field {...common}>{(p) => <Input {...p} value={(value as string) ?? ''} maxLength={s.max_length} onChange={(e) => onChange(f.key, e.target.value)} />}</Field>
            break
          case 'textarea':
            control = <Field {...common}>{(p) => <Textarea {...p} rows={3} value={(value as string) ?? ''} maxLength={s.max_length} onChange={(e) => onChange(f.key, e.target.value)} />}</Field>
            break
          case 'number':
          case 'money':
            control = (
              <Field {...common} hint={f.data_type === 'money' ? 'Rupiah' : rangeHint(s.min, s.max)}>
                {(p) => (
                  <Input
                    {...p}
                    type="number"
                    inputMode="decimal"
                    min={s.min}
                    max={s.max}
                    step={f.data_type === 'money' ? '0.01' : s.decimals ? String(10 ** -s.decimals) : '1'}
                    value={value === null || value === undefined ? '' : String(value)}
                    onChange={(e) => onChange(f.key, e.target.value === '' ? null : e.target.value)}
                  />
                )}
              </Field>
            )
            break
          case 'date':
            control = (
              <Field {...common}>
                {(p) => (
                  <Input
                    {...p}
                    type="date"
                    min={s.not_before_today ? localToday() : undefined}
                    max={s.not_after_today ? localToday() : undefined}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(f.key, e.target.value || null)}
                  />
                )}
              </Field>
            )
            break
          case 'datetime':
            control = <Field {...common}>{(p) => <Input {...p} type="datetime-local" value={(value as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || null)} />}</Field>
            break
          case 'select':
            control = (
              <Field {...common}>
                {(p) => (
                  <Select {...p} value={(value as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || null)}>
                    <option value="">— Pilih —</option>
                    {(s.options ?? []).map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            )
            break
          case 'multiselect': {
            const picked = Array.isArray(value) ? (value as string[]) : []
            control = (
              <fieldset>
                <legend className="mb-1.5 block text-sm font-semibold text-slate-700">
                  {f.label}
                  {common.required && <span className="text-red-600"> *</span>}
                </legend>
                <div className="flex flex-wrap gap-x-4 gap-y-2">
                  {(s.options ?? []).map((o) => (
                    <label key={o.value} className="inline-flex items-center gap-2 text-sm text-slate-700">
                      <input
                        type="checkbox"
                        className="size-4 rounded border-slate-300 accent-brand-700"
                        checked={picked.includes(o.value)}
                        onChange={(e) => onChange(f.key, e.target.checked ? [...picked, o.value] : picked.filter((v) => v !== o.value))}
                      />
                      {o.label}
                    </label>
                  ))}
                </div>
                {common.error && <p className="mt-1.5 text-sm text-red-700">{common.error}</p>}
              </fieldset>
            )
            break
          }
          case 'boolean':
            control = (
              <Field {...common}>
                {(p) => (
                  <Select {...p} value={value === true ? '1' : value === false ? '0' : ''} onChange={(e) => onChange(f.key, e.target.value === '' ? null : e.target.value === '1')}>
                    <option value="">— Pilih —</option>
                    <option value="1">Ya</option>
                    <option value="0">Tidak</option>
                  </Select>
                )}
              </Field>
            )
            break
          case 'user':
            control = <MemberPicker {...common} value={(value as string) ?? ''} onChange={(v) => onChange(f.key, v || null)} />
            break
          case 'location':
            control = (
              <Field {...common} hint="Hanya lokasi dalam cakupan data Anda.">
                {(p) => (
                  <Select {...p} value={(value as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || null)}>
                    <option value="">— Pilih —</option>
                    {locations.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            )
            break
          case 'counterparty': {
            const v = (value as { name?: string; contact?: string } | null) ?? {}
            control = (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field {...common} label={`${f.label}: nama`}>
                  {(p) => <Input {...p} maxLength={150} value={v.name ?? ''} onChange={(e) => onChange(f.key, { ...v, name: e.target.value })} />}
                </Field>
                <Field label={`${f.label}: kontak`} hint="Data pribadi; hanya tampil bagi yang berizin." error={errors[`field_values.${f.key}.contact`]?.[0]}>
                  {(p) => <Input {...p} maxLength={150} value={v.contact ?? ''} onChange={(e) => onChange(f.key, { ...v, contact: e.target.value })} />}
                </Field>
              </div>
            )
            break
          }
          case 'attachment':
            control = <AttachmentInput {...common} typeId={typeId} field={f} value={value as AttachmentSummary | string | null} onChange={(v) => onChange(f.key, v)} />
            break
        }
        return (
          <div key={f.key} className={wide ? 'sm:col-span-2' : undefined}>
            {control}
            {s.help && <p className="mt-1 text-xs text-slate-600">{s.help}</p>}
          </div>
        )
      })}
    </div>
  )
}

function rangeHint(min?: number, max?: number): string | undefined {
  if (min !== undefined && max !== undefined) return `${min} sampai ${max}`
  if (min !== undefined) return `Minimal ${min}`
  if (max !== undefined) return `Maksimal ${max}`
  return undefined
}

/** A member of the organization by name (no email or other personal data). */
function MemberPicker({ label, required, error, value, onChange }: { label: string; required: boolean; error?: string; value: string; onChange: (id: string) => void }) {
  const [search, setSearch] = useState('')
  const members = useQuery({
    queryKey: ['/transaction-members', search, value],
    queryFn: () => typesApi.members(search, value ? [value] : []),
    staleTime: 30_000,
  })
  return (
    <Field label={label} required={required} error={error} hint="Ketik untuk mencari nama.">
      {(p) => (
        <div className="flex flex-col gap-2 sm:flex-row">
          <Input aria-label={`Cari ${label}`} placeholder="Cari nama" value={search} onChange={(e) => setSearch(e.target.value)} className="sm:max-w-44" />
          <Select {...p} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">— Pilih —</option>
            {(members.data?.data ?? []).map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </Select>
        </div>
      )}
    </Field>
  )
}

/** Upload one file for the field; the form sends the returned id. */
function AttachmentInput({ label, required, error, typeId, field, value, onChange }: {
  label: string
  required: boolean
  error?: string
  typeId: string
  field: TypeField
  value: AttachmentSummary | string | null
  onChange: (value: AttachmentSummary | null) => void
}) {
  const [busy, setBusy] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const kinds: AttachmentKind[] = field.settings.accept?.length ? field.settings.accept : ['pdf', 'image', 'office']
  const accept = kinds.map((k) => attachmentAccept[k]).join(',')
  const file = value && typeof value === 'object' ? value : null
  const hint = `${kinds.map((k) => ({ pdf: 'PDF', image: 'gambar', office: 'Word/Excel' })[k]).join(', ')}${field.settings.max_mb ? `, maks. ${field.settings.max_mb} MB` : ''}`

  return (
    <Field label={label} required={required} error={uploadError ?? error} hint={hint}>
      {(p) =>
        file ? (
          <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm ring-1 ring-slate-200">
            <Paperclip className="size-4 text-slate-500" aria-hidden />
            <span className="min-w-0 flex-1 truncate">{file.name}</span>
            <span className="text-xs text-slate-500">{fileSize(file.size)}</span>
            <button type="button" className="rounded p-1 text-slate-500 hover:bg-slate-200" onClick={() => onChange(null)} aria-label={`Hapus ${file.name}`}>
              <X className="size-4" aria-hidden />
            </button>
          </div>
        ) : (
          <input
            id={p.id}
            aria-invalid={p['aria-invalid']}
            aria-describedby={p['aria-describedby']}
            type="file"
            accept={accept}
            disabled={busy}
            className="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
            onChange={async (e) => {
              const picked = e.target.files?.[0]
              if (!picked) return
              setBusy(true)
              setUploadError(null)
              try {
                onChange((await typesApi.upload(typeId, field.key, picked)).data)
              } catch (ex) {
                setUploadError(errorMessage(ex))
              } finally {
                setBusy(false)
                e.target.value = ''
              }
            }}
          />
        )
      }
    </Field>
  )
}
