import { useCallback, useState, type FormEvent } from 'react'
import { ApiError, type ApiClient, type Location } from '../core/api/client'
import { ErrorMessage, useLoad } from './shared'

const blank = { name: '', type: '', address: '' }

export function LocationsPage({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.locations(), [api])
  const { value, error, loading, reload } = useLoad(load)
  const [form, setForm] = useState(blank)
  const [creating, setCreating] = useState(false)
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState('')

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setActionError('')
    try {
      await api.createLocation({ name: form.name.trim(), type: form.type.trim() || undefined, address: form.address.trim() || undefined })
      setForm(blank); setCreating(false); reload()
    } catch (e) { setActionError(e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : 'Unable to save location') } finally { setSaving(false) }
  }
  const remove = async (location: Location) => {
    if (!confirm(`Delete location "${location.name}"?`)) return
    setActionError('')
    try { await api.deleteLocation(location.id); reload() } catch (e) { setActionError(e instanceof Error ? e.message : 'Unable to delete location') }
  }
  const input = (name: keyof typeof blank, labelText: string, required = false) => <label className="block text-sm">{labelText}<input required={required} value={form[name]} onChange={e => setForm({ ...form, [name]: e.target.value })} className="mt-1 w-full rounded border p-2" /></label>

  return <>
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div><h2 className="text-2xl font-bold">Locations</h2><p className="mt-1 text-slate-600">{value ? `${value.data.length} location(s) in this project` : 'Places assets can be moved to.'}</p></div>
      {!creating && <button className="rounded bg-blue-600 px-4 py-2 text-sm text-white" onClick={() => setCreating(true)}>New location</button>}
    </div>
    <ErrorMessage error={error || actionError} />

    {creating && <form onSubmit={submit} className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-3">
      {input('name', 'Name', true)}{input('type', 'Type (e.g. warehouse)')}{input('address', 'Address')}
      <div className="flex gap-2 sm:col-span-3"><button disabled={saving} className="rounded bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-60">{saving ? 'Saving…' : 'Save location'}</button><button type="button" className="rounded border px-4 py-2 text-sm" onClick={() => { setCreating(false); setForm(blank) }}>Cancel</button></div>
    </form>}

    {!loading && !error && value?.data.length === 0 && !creating && <p className="mt-5 rounded-lg border bg-white p-6 text-sm text-slate-600">No locations yet. Add one so assets can be moved between places.</p>}
    <div className="mt-5 grid gap-3 sm:grid-cols-2">{value?.data.map(location => <article key={location.id} className="flex items-start justify-between gap-3 rounded-lg border bg-white p-4"><div><h3 className="font-semibold">{location.name}</h3><p className="mt-1 text-sm text-slate-500">{location.type || 'Unclassified'}{location.address ? ` · ${location.address}` : ''}</p></div><button className="text-sm text-red-600 hover:underline" onClick={() => remove(location)}>Delete</button></article>)}</div>
  </>
}
