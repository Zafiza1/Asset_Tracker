import { useCallback, useState, type FormEvent } from 'react'
import type { ApiClient, Integration } from '../core/api/client'
import { Can } from '../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../pages/shared'
import { integrationDefinition } from './registry'

/** Connections to external technology (RFID, GPS, …) for the selected project. */
export function IntegrationsPage({ api }: { api: ApiClient }) {
  const load = useCallback(async () => {
    const [integrations, available] = await Promise.all([api.integrations(), api.availableIntegrations().catch(() => ['rfid', 'gps'])])
    return { integrations: integrations.data, available }
  }, [api])
  const { value, error, loading, reload } = useLoad(load)
  const [creating, setCreating] = useState(false)
  const [message, setMessage] = useState<{ text: string; tone: 'success' | 'warning' }>()
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<{ data?: { message?: string; status?: string }; message?: string } | unknown>, done: string) => {
    setActionError(''); setMessage(undefined)
    try {
      const result = await action() as { message?: string; data?: { message?: string } } | undefined
      setMessage({ text: result?.message ?? result?.data?.message ?? done, tone: 'success' }); reload()
    } catch (e) { setMessage({ text: errorText(e), tone: 'warning' }); reload() }
  }

  return <>
    <PageHeader title="Integrations" description="Hardware and external systems feed the platform through integrations. Each adapter normalizes its data into standard events, so Core never sees device-specific formats."
      actions={!creating && <Can permission="integration.configure"><button className={buttonClass} onClick={() => setCreating(true)}>New integration</button></Can>} />
    <ErrorMessage error={error || actionError} />
    <Notice message={message?.text} tone={message?.tone} />
    {creating && value && <IntegrationForm api={api} types={value.available} onCancel={() => setCreating(false)} onCreated={() => { setCreating(false); setMessage({ text: 'Integration created. Connect it to start receiving data.', tone: 'success' }); reload() }} />}
    {!loading && value && !value.integrations.length && !creating && <div className="mt-5"><Empty>No integrations yet. Add RFID or GPS to start receiving device data.</Empty></div>}
    <div className="mt-5 grid gap-4 lg:grid-cols-2">{value?.integrations.map(integration => <IntegrationCard key={integration.id} integration={integration}
      action={a => run(() => api.integrationAction(integration.id, a), `Integration ${a === 'test' ? 'test passed' : `${a}ed`}.`)}
      health={() => run(async () => { const r = await api.integrationHealth(integration.id); return { message: `Health: ${r.status ?? 'unknown'}${r.message ? ` — ${r.message}` : ''}` } }, 'Health checked.')}
      remove={() => confirm(`Delete integration "${integration.name}"? Its devices stay but stop receiving data.`) && run(() => api.deleteIntegration(integration.id), 'Integration deleted.')} />)}</div>
  </>
}

function IntegrationCard({ integration, action, health, remove }: { integration: Integration; action: (a: 'connect' | 'disconnect' | 'test') => void; health: () => void; remove: () => void }) {
  const definition = integrationDefinition(integration.type)
  return <article className="rounded-lg border bg-white p-5">
    <div className="flex items-start justify-between gap-3">
      <div><h3 className="font-semibold">{integration.name}</h3><p className="text-xs text-slate-500">{definition.name}{integration.provider ? ` · ${integration.provider}` : ''}</p></div>
      <StatusBadge status={integration.status} />
    </div>
    <dl className="mt-3 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-xs">
      {Object.entries(integration.config ?? {}).map(([key, val]) => <div key={key} className="contents"><dt className="text-slate-500">{key}</dt><dd className="break-all font-mono">{String(val)}</dd></div>)}
      <dt className="text-slate-500">last connected</dt><dd>{formatDate(integration.last_connected_at)}</dd>
      <dt className="text-slate-500">last health check</dt><dd>{formatDate(integration.last_health_check_at)}</dd>
    </dl>
    <div className="mt-4 flex flex-wrap gap-2">
      {integration.status !== 'connected'
        ? <Can permission="integration.connect"><button className={buttonClass} onClick={() => action('connect')}>Connect</button></Can>
        : <Can permission="integration.disconnect"><button className={secondaryButtonClass} onClick={() => action('disconnect')}>Disconnect</button></Can>}
      <Can permission="integration.test"><button className={secondaryButtonClass} onClick={() => action('test')}>Test connection</button></Can>
      <button className={secondaryButtonClass} onClick={health}>Health check</button>
      <Can permission="integration.configure"><button className="text-sm text-red-600 hover:underline" onClick={remove}>Delete</button></Can>
    </div>
  </article>
}

function IntegrationForm({ api, types, onCancel, onCreated }: { api: ApiClient; types: string[]; onCancel: () => void; onCreated: () => void }) {
  const [type, setType] = useState(types[0] ?? 'rfid')
  const [name, setName] = useState('')
  const [provider, setProvider] = useState('')
  const [config, setConfig] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const definition = integrationDefinition(type)

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError('')
    const values = Object.fromEntries(definition.fields.filter(f => config[f.key]).map(f => [f.key, f.type === 'number' ? Number(config[f.key]) : config[f.key]]))
    try {
      // Validate with the adapter first: it knows its own config rules.
      const check = await api.validateIntegrationConfig(type, values)
      if (!check.valid) { setError(Object.values(check.errors).join(' ')); return }
      await api.createIntegration({ name: name.trim() || `${definition.name} Gateway`, type, provider: provider.trim() || undefined, config: values })
      onCreated()
    } catch (e) { setError(errorText(e)) } finally { setSaving(false) }
  }

  return <form onSubmit={submit} className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
    <label className="block text-sm">Type<select className={inputClass} value={type} onChange={e => { setType(e.target.value); setConfig({}) }}>{types.map(t => <option key={t} value={t}>{integrationDefinition(t).name}</option>)}</select></label>
    <label className="block text-sm">Name<input className={inputClass} value={name} onChange={e => setName(e.target.value)} placeholder={`${definition.name} Gateway`} /></label>
    <p className="text-xs text-slate-500 sm:col-span-2">{definition.description}</p>
    <label className="block text-sm">Provider<input className={inputClass} value={provider} onChange={e => setProvider(e.target.value)} placeholder="Vendor or gateway name" /></label>
    {definition.fields.map(field => <label key={field.key} className="block text-sm">{field.label}
      <input required={field.required} type={field.type ?? 'text'} className={inputClass} placeholder={field.placeholder} value={config[field.key] ?? ''} onChange={e => setConfig({ ...config, [field.key]: e.target.value })} />
      {field.help && <span className="mt-1 block text-xs text-slate-500">{field.help}</span>}
    </label>)}
    <div className="sm:col-span-2"><ErrorMessage error={error} /></div>
    <div className="flex gap-2 sm:col-span-2"><button disabled={saving} className={buttonClass}>{saving ? 'Saving…' : 'Create integration'}</button><button type="button" className={secondaryButtonClass} onClick={onCancel}>Cancel</button></div>
  </form>
}
