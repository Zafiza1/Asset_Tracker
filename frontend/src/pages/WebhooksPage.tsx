import { useCallback, useState, type FormEvent } from 'react'
import type { ApiClient, Webhook, WebhookDelivery, WebhookStats } from '../core/api/client'
import { Can } from '../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from './shared'

// Standard events the platform publishes; custom events from POST /v1/events can be typed in.
const knownEvents = [
  'asset.created', 'asset.updated', 'asset.deleted', 'asset.location.updated', 'asset.status.changed', 'asset.detected',
  'integration.connected', 'integration.disconnected', 'integration.degraded',
  'project.module.installed', 'project.module.enabled', 'project.module.disabled',
]

/** Outgoing webhooks: signed (HMAC-SHA256) deliveries to the customer's systems, retried asynchronously. */
export function WebhooksPage({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.webhooks(), [api])
  const { value, error, loading, reload } = useLoad(load)
  const [creating, setCreating] = useState(false)
  const [secret, setSecret] = useState('')
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload() } catch (e) { setActionError(errorText(e)) }
  }

  return <>
    <PageHeader title="Webhooks" description="Send platform events to ERP, WMS or any HTTPS endpoint. Each request carries an X-Webhook-Signature (HMAC-SHA256 of the body)."
      actions={!creating && <Can permission="webhook.create"><button className={buttonClass} onClick={() => setCreating(true)}>New webhook</button></Can>} />
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />
    {secret && <Notice tone="warning" message={<>Signing secret (shown once — store it now): <code className="break-all font-mono">{secret}</code></>} />}

    {creating && <WebhookForm onCancel={() => setCreating(false)} onSubmit={input => run(async () => {
      const webhook = await api.createWebhook(input); setSecret(webhook.secret ?? ''); setCreating(false)
    }, 'Webhook created.')} />}
    {!loading && value && !value.data.length && !creating && <div className="mt-5"><Empty>No webhooks yet.</Empty></div>}
    <div className="mt-5 space-y-4">{value?.data.map(webhook => <WebhookCard key={webhook.id} api={api} webhook={webhook} run={run} onSecret={setSecret} />)}</div>
  </>
}

function WebhookCard({ api, webhook, run, onSecret }: { api: ApiClient; webhook: Webhook; run: (action: () => Promise<unknown>, done: string) => Promise<void>; onSecret: (secret: string) => void }) {
  const [details, setDetails] = useState<{ stats: WebhookStats; deliveries: WebhookDelivery[] } | null>(null)
  const toggleDetails = async () => {
    if (details) { setDetails(null); return }
    const [stats, deliveries] = await Promise.all([api.webhookStats(webhook.id), api.webhookDeliveries(webhook.id)])
    setDetails({ stats, deliveries: deliveries.data })
  }
  const test = () => run(async () => {
    const result = (await api.testWebhook(webhook.id)).data
    if (!result.success) throw new Error(`Test delivery failed${result.status ? ` (HTTP ${result.status})` : ''}.`)
  }, 'Test delivery succeeded.')

  return <article className="rounded-lg border bg-white p-5">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><h3 className="font-semibold">{webhook.name}</h3><p className="break-all font-mono text-xs text-slate-500">{webhook.endpoint}</p></div>
      <StatusBadge status={webhook.active ? 'active' : 'inactive'} />
    </div>
    <div className="mt-3 flex flex-wrap gap-1">{webhook.events.map(e => <span key={e} className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">{e}</span>)}</div>
    <div className="mt-4 flex flex-wrap gap-2">
      <Can permission="webhook.test"><button className={secondaryButtonClass} onClick={test}>Send test</button></Can>
      <Can permission="webhook.update"><button className={secondaryButtonClass} onClick={() => run(() => api.toggleWebhook(webhook.id), webhook.active ? 'Webhook paused.' : 'Webhook activated.')}>{webhook.active ? 'Pause' : 'Activate'}</button></Can>
      <Can permission="webhook.update"><button className={secondaryButtonClass} onClick={() => confirm('Generate a new signing secret? The old one stops working immediately.') && run(async () => onSecret((await api.regenerateWebhookSecret(webhook.id)).secret), 'Secret regenerated.')}>New secret</button></Can>
      <button className={secondaryButtonClass} onClick={toggleDetails}>{details ? 'Hide deliveries' : 'Deliveries'}</button>
      <Can permission="webhook.delete"><button className="text-sm text-red-600 hover:underline" onClick={() => confirm(`Delete webhook "${webhook.name}"?`) && run(() => api.deleteWebhook(webhook.id), 'Webhook deleted.')}>Delete</button></Can>
    </div>
    {details && <div className="mt-4 text-sm">
      <p className="text-slate-600">{details.stats.total} deliveries · {details.stats.delivered} delivered · {details.stats.failed} failed · {details.stats.retrying + details.stats.pending} pending/retrying · success rate {details.stats.success_rate}%</p>
      <ul className="mt-2 divide-y">{details.deliveries.map(d => <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
        <span className="font-mono">{d.event_type}</span>
        <span className="flex items-center gap-2"><StatusBadge status={d.status} />attempt {d.attempt}/{d.max_attempts}{d.response_status ? ` · HTTP ${d.response_status}` : ''}<span className="text-slate-500">{formatDate(d.created_at)}</span></span>
        {d.error_message && <span className="w-full text-red-600">{d.error_message}</span>}
      </li>)}{!details.deliveries.length && <li className="py-2 text-slate-500">No deliveries yet.</li>}</ul>
    </div>}
  </article>
}

function WebhookForm({ onCancel, onSubmit }: { onCancel: () => void; onSubmit: (input: { name: string; endpoint: string; events: string[] }) => void }) {
  const [name, setName] = useState('')
  const [endpoint, setEndpoint] = useState('')
  const [events, setEvents] = useState<string[]>(['asset.created', 'asset.location.updated', 'asset.status.changed'])
  const [custom, setCustom] = useState('')
  const toggle = (event: string) => setEvents(current => current.includes(event) ? current.filter(e => e !== event) : [...current, event])
  const submit = (event: FormEvent) => {
    event.preventDefault()
    const extra = custom.split(',').map(e => e.trim()).filter(Boolean)
    onSubmit({ name: name.trim(), endpoint: endpoint.trim(), events: [...new Set([...events, ...extra])] })
  }
  return <form onSubmit={submit} className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
    <label className="block text-sm">Name<input required className={inputClass} value={name} onChange={e => setName(e.target.value)} placeholder="ERP sync" /></label>
    <label className="block text-sm">Endpoint URL<input required type="url" className={inputClass} value={endpoint} onChange={e => setEndpoint(e.target.value)} placeholder="https://erp.example.com/hooks/assets" /></label>
    <fieldset className="sm:col-span-2"><legend className="text-sm">Events</legend>
      <div className="mt-2 grid gap-1 sm:grid-cols-3">{knownEvents.map(e => <label key={e} className="flex items-center gap-2 font-mono text-xs"><input type="checkbox" checked={events.includes(e)} onChange={() => toggle(e)} />{e}</label>)}</div>
    </fieldset>
    <label className="block text-sm sm:col-span-2">Other events (comma separated)<input className={inputClass} value={custom} onChange={e => setCustom(e.target.value)} placeholder="maintenance.completed, delivery.dispatched" /></label>
    <div className="flex gap-2 sm:col-span-2"><button disabled={!events.length && !custom.trim()} className={buttonClass}>Create webhook</button><button type="button" className={secondaryButtonClass} onClick={onCancel}>Cancel</button></div>
  </form>
}
