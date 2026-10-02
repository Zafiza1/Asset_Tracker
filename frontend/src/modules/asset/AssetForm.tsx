import { useState, type FormEvent } from 'react'
import { ApiError, type Asset, type AssetInput, type CustomField } from '../../core/api/client'

type Props = {
  asset?: Asset | null
  customFields: CustomField[]
  onSubmit: (input: AssetInput) => Promise<void>
  onCancel: () => void
}

// Field types the form can edit inline; the rest (json, multiselect,
// relation) are kept as-is and remain editable through the API.
const editable = ['text', 'textarea', 'number', 'decimal', 'boolean', 'date', 'datetime', 'select']

const toInputValue = (value: unknown) => value === null || value === undefined ? '' : String(value)

export function AssetForm({ asset, customFields, onSubmit, onCancel }: Props) {
  const [serial, setSerial] = useState(asset?.serial_number ?? '')
  const [name, setName] = useState(asset?.name ?? '')
  const [type, setType] = useState(asset?.asset_type ?? '')
  const [status, setStatus] = useState(asset?.status ?? 'active')
  const [description, setDescription] = useState(asset?.description ?? '')
  const [metadata, setMetadata] = useState<Record<string, unknown>>(asset?.metadata ?? {})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const fields = customFields.filter(field => field.active && editable.includes(field.type))
  const setField = (key: string, value: unknown) => setMetadata(current => ({ ...current, [key]: value }))

  const coerce = (field: CustomField, raw: string) => {
    if (raw === '') return null
    if (field.type === 'number') return Number.parseInt(raw, 10)
    if (field.type === 'decimal') return Number.parseFloat(raw)
    return raw
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true); setErrors({}); setMessage('')
    try {
      await onSubmit({ serial_number: serial, name, asset_type: type || undefined, status, description: description || undefined, metadata })
    } catch (e) {
      if (e instanceof ApiError) { setErrors(e.errors); setMessage(e.message) } else setMessage('Unable to save asset')
    } finally { setSaving(false) }
  }

  const fieldError = (key: string) => errors[key]?.[0]
  const input = 'mt-1 w-full rounded border p-2'

  return <form onSubmit={submit} className="space-y-4">
    {message && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{message}</p>}
    <div className="grid gap-4 sm:grid-cols-2">
      <label className="block text-sm">Serial number<input required className={`${input} font-mono`} value={serial} onChange={e => setSerial(e.target.value)} />
        <span className="text-xs text-slate-500">Your own identifier; unique within this project.</span>
        {fieldError('serial_number') && <span className="block text-xs text-red-600">{fieldError('serial_number')}</span>}</label>
      <label className="block text-sm">Name<input required className={input} value={name} onChange={e => setName(e.target.value)} />
        {fieldError('name') && <span className="block text-xs text-red-600">{fieldError('name')}</span>}</label>
      <label className="block text-sm">Type<input className={input} placeholder="e.g. C2H2, Vehicle, Forklift" value={type} onChange={e => setType(e.target.value)} /></label>
      <label className="block text-sm">Status<input required className={input} list="asset-statuses" value={status} onChange={e => setStatus(e.target.value)} />
        <datalist id="asset-statuses"><option value="active" /><option value="inactive" /><option value="maintenance" /><option value="retired" /></datalist></label>
    </div>
    <label className="block text-sm">Description<textarea className={input} rows={2} value={description} onChange={e => setDescription(e.target.value)} /></label>

    {fields.length > 0 && <fieldset className="rounded border p-4"><legend className="px-1 text-sm font-medium">Custom fields</legend>
      <div className="grid gap-4 sm:grid-cols-2">{fields.map(field => {
        const value = metadata[field.key]
        const error = fieldError(`metadata.${field.key}`)
        const title = <>{field.label}{field.required && <span className="text-red-600"> *</span>}</>
        let control
        if (field.type === 'boolean') control = <input type="checkbox" className="ml-2" checked={value === true} onChange={e => setField(field.key, e.target.checked)} />
        else if (field.type === 'select') control = <select className={input} required={field.required} value={toInputValue(value)} onChange={e => setField(field.key, e.target.value || null)}><option value="">—</option>{(field.options ?? []).map(option => <option key={option}>{option}</option>)}</select>
        else if (field.type === 'textarea') control = <textarea className={input} required={field.required} value={toInputValue(value)} onChange={e => setField(field.key, e.target.value || null)} />
        else control = <input className={input} required={field.required}
          type={{ number: 'number', decimal: 'number', date: 'date', datetime: 'datetime-local' }[field.type] ?? 'text'}
          step={field.type === 'decimal' ? 'any' : undefined}
          value={toInputValue(value)} onChange={e => setField(field.key, coerce(field, e.target.value))} />
        return <label key={field.id} className="block text-sm">{title}{control}{error && <span className="block text-xs text-red-600">{error}</span>}</label>
      })}</div>
    </fieldset>}

    <div className="flex justify-end gap-2">
      <button type="button" className="rounded border px-4 py-2 text-sm" onClick={onCancel}>Cancel</button>
      <button disabled={saving} className="rounded bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-60">{saving ? 'Saving…' : asset ? 'Save changes' : 'Create asset'}</button>
    </div>
  </form>
}
