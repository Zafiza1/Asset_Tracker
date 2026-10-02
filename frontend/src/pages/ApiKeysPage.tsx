import { useCallback, useState, type FormEvent } from 'react'
import type { ApiClient } from '../core/api/client'
import { Can } from '../core/permissions/AccessContext'
import { ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from './shared'

const scopes = [
  ['event.ingest', 'Publish standard events (POST /api/v1/events)'],
  ['integration.ingest', 'Send raw device data to an integration (POST /api/v1/integrations/{id}/ingest)'],
]

/** Project-scoped keys for gateways and external systems (sent as X-Api-Key). */
export function ApiKeysPage({ api }: { api: ApiClient }) {
  const load = useCallback(async () => {
    const [keys, integrations] = await Promise.all([api.apiKeys(), api.integrations().catch(() => ({ data: [] }))])
    return { keys, integrations: integrations.data }
  }, [api])
  const { value, error, reload } = useLoad(load)
  const [form, setForm] = useState({ name: '', scopes: ['event.ingest'], integration_id: '' })
  const [plainKey, setPlainKey] = useState('')
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload() } catch (e) { setActionError(errorText(e)) }
  }
  const submit = (event: FormEvent) => {
    event.preventDefault()
    run(async () => {
      const key = await api.createApiKey({ name: form.name.trim(), scopes: form.scopes, integration_id: form.integration_id ? Number(form.integration_id) : undefined })
      setPlainKey(key.key ?? ''); setForm({ name: '', scopes: ['event.ingest'], integration_id: '' })
    }, 'API key created.')
  }
  const toggleScope = (scope: string) => setForm(f => ({ ...f, scopes: f.scopes.includes(scope) ? f.scopes.filter(s => s !== scope) : [...f.scopes, scope] }))

  return <>
    <PageHeader title="API keys" description="Gateways and external applications authenticate with an X-Api-Key header. The key alone decides the organization and project it can write to." />
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />
    {plainKey && <Notice tone="warning" message={<>New key (shown once — copy it now): <code className="break-all font-mono">{plainKey}</code></>} />}

    <Can permission="api-key.manage"><form onSubmit={submit} className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
      <label className="block text-sm">Name<input required className={inputClass} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="Warehouse RFID gateway" /></label>
      <label className="block text-sm">Limit to integration<select className={inputClass} value={form.integration_id} onChange={e => setForm({ ...form, integration_id: e.target.value })}><option value="">Any integration in this project</option>{value?.integrations.map(i => <option key={i.id} value={i.id}>{i.name}</option>)}</select></label>
      <fieldset className="sm:col-span-2"><legend className="text-sm">Scopes</legend>{scopes.map(([scope, text]) => <label key={scope} className="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.scopes.includes(scope)} onChange={() => toggleScope(scope)} /><span className="font-mono text-xs">{scope}</span><span className="text-slate-500">{text}</span></label>)}</fieldset>
      <div className="sm:col-span-2"><button disabled={!form.scopes.length} className={buttonClass}>Create key</button></div>
    </form></Can>

    <div className="mt-5 overflow-x-auto rounded-lg border bg-white">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Name</th><th className="p-3">Prefix</th><th className="p-3">Scopes</th><th className="p-3">Last used</th><th className="p-3">Status</th><th className="p-3"></th></tr></thead>
        <tbody>{value?.keys.map(key => <tr key={key.id} className="border-t">
          <td className="p-3">{key.name}</td><td className="p-3 font-mono text-xs">{key.prefix}…</td><td className="p-3 font-mono text-xs">{key.scopes.join(', ')}</td>
          <td className="p-3 text-xs text-slate-500">{formatDate(key.last_used_at)}</td>
          <td className="p-3"><StatusBadge status={key.revoked_at ? 'revoked' : 'active'} /></td>
          <td className="p-3 text-right">{!key.revoked_at && <Can permission="api-key.manage"><button className={secondaryButtonClass} onClick={() => confirm(`Revoke "${key.name}"? Devices using it stop working.`) && run(() => api.revokeApiKey(key.id), 'Key revoked.')}>Revoke</button></Can>}</td>
        </tr>)}
          {value && !value.keys.length && <tr><td colSpan={6} className="p-6 text-center text-slate-500">No API keys.</td></tr>}</tbody>
      </table>
    </div>
  </>
}
