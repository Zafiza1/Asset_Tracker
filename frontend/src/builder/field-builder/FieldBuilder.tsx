import { useCallback, useState, type FormEvent } from 'react'
import type { ApiClient, CustomFieldInput } from '../../core/api/client'
import { Can } from '../../core/permissions/AccessContext'
import { ErrorMessage, buttonClass, errorText, inputClass, useLoad } from '../../pages/shared'

const types = ['text', 'number', 'decimal', 'boolean', 'date', 'datetime', 'select', 'multiselect', 'textarea', 'json', 'relation']
const blank = { label: '', key: '', type: 'text', required: false, options: '', default_value: '', visibility: 'visible' }
const toKey = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '')

/**
 * Field Builder: project-scoped custom field definitions. Values live in the
 * asset's metadata, so adding a field never needs a database migration.
 */
export function FieldBuilder({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.customFields(), [api])
  const { value, error, reload } = useLoad(load)
  const [form, setForm] = useState(blank)
  const [keyEdited, setKeyEdited] = useState(false)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')
  const hasOptions = form.type === 'select' || form.type === 'multiselect'

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setMessage(''); setActionError('')
    const input: CustomFieldInput = {
      label: form.label.trim(), key: form.key, type: form.type, required: form.required, visibility: form.visibility,
      sort_order: value?.length ?? 0,
      options: hasOptions ? form.options.split(',').map(o => o.trim()).filter(Boolean) : undefined,
      default_value: form.default_value.trim() || undefined,
    }
    try { await api.createCustomField(input); setMessage('Field created.'); setForm(blank); setKeyEdited(false); reload() } catch (e) { setActionError(errorText(e)) }
  }
  const remove = async (id: number) => {
    if (!window.confirm('Remove this field definition? Existing asset metadata is retained.')) return
    try { await api.deleteCustomField(id); reload() } catch (e) { setActionError(errorText(e)) }
  }

  return <div className="grid gap-6 lg:grid-cols-2">
    <Can permission="asset.manage-custom-fields"><form onSubmit={submit} className="space-y-4 rounded-lg border bg-white p-5">
      <h3 className="font-semibold">New field</h3>
      <label className="block text-sm">Label<input required className={inputClass} value={form.label} onChange={e => setForm({ ...form, label: e.target.value, key: keyEdited ? form.key : toKey(e.target.value) })} /></label>
      <label className="block text-sm">Key<input required pattern="[a-z][a-z0-9_]*" className={`${inputClass} font-mono`} value={form.key} onChange={e => { setForm({ ...form, key: e.target.value }); setKeyEdited(e.target.value !== '') }} /></label>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="block text-sm">Type<select className={inputClass} value={form.type} onChange={e => setForm({ ...form, type: e.target.value })}>{types.map(t => <option key={t}>{t}</option>)}</select></label>
        <label className="block text-sm">Visibility<select className={inputClass} value={form.visibility} onChange={e => setForm({ ...form, visibility: e.target.value })}>{['visible', 'readonly', 'hidden'].map(v => <option key={v}>{v}</option>)}</select></label>
      </div>
      {hasOptions && <label className="block text-sm">Options (comma separated)<input required className={inputClass} value={form.options} onChange={e => setForm({ ...form, options: e.target.value })} placeholder="3 KG, 6 KG, 12 KG" /></label>}
      <label className="block text-sm">Default value<input className={inputClass} value={form.default_value} onChange={e => setForm({ ...form, default_value: e.target.value })} /></label>
      <label className="flex gap-2 text-sm"><input type="checkbox" checked={form.required} onChange={e => setForm({ ...form, required: e.target.checked })} /> Required</label>
      <button className={buttonClass}>Create field</button>
      {message && <p className="text-sm text-green-700">{message}</p>}
    </form></Can>
    <section className="rounded-lg border bg-white p-5">
      <h3 className="font-semibold">Project fields</h3>
      <ErrorMessage error={error || actionError} />
      <ul className="mt-3 divide-y">{value?.map(field => <li key={field.id} className="flex items-center justify-between gap-3 py-3 text-sm">
        <span><strong>{field.label}</strong> <span className="font-mono text-slate-500">{field.key}</span>{field.options?.length ? <span className="block text-xs text-slate-500">{field.options.join(' · ')}</span> : null}</span>
        <span className="flex items-center gap-3">{field.type}{field.required ? ' · required' : ''}<Can permission="asset.manage-custom-fields"><button className="text-red-600" onClick={() => remove(field.id)}>Remove</button></Can></span>
      </li>)}{!value?.length && !error && <li className="py-3 text-sm text-slate-500">No field definitions yet.</li>}</ul>
    </section>
  </div>
}
