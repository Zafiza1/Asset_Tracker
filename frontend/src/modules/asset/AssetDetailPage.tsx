import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import type { ApiClient, CustomField, Location } from '../../core/api/client'
import { ErrorMessage, Section, StatusBadge, formatDate, label, useLoad } from '../../pages/shared'
import { AssetForm } from './AssetForm'

const display = (value: unknown) => value === null || value === undefined || value === '' ? '—'
  : typeof value === 'object' ? JSON.stringify(value) : String(value)

export function AssetDetailPage({ api }: { api: ApiClient }) {
  const { systemId = '' } = useParams()
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const [actionError, setActionError] = useState('')
  const [moveTo, setMoveTo] = useState('')
  const [locations, setLocations] = useState<Location[]>([])
  const [customFields, setCustomFields] = useState<CustomField[]>([])

  const loadAsset = useCallback(() => api.asset(systemId), [api, systemId])
  const loadMovements = useCallback(() => api.movements(systemId), [api, systemId])
  const loadActivity = useCallback(() => api.assetActivity(systemId), [api, systemId])
  const asset = useLoad(loadAsset)
  const movements = useLoad(loadMovements)
  const activity = useLoad(loadActivity)

  useEffect(() => {
    api.locations().then(r => setLocations(r.data)).catch(() => setLocations([]))
    api.customFields().then(setCustomFields).catch(() => setCustomFields([]))
  }, [api])

  const refresh = () => { asset.reload(); movements.reload(); activity.reload() }
  const remove = async () => {
    if (!window.confirm('Delete this asset? Its history is kept for audit.')) return
    try { await api.deleteAsset(systemId); navigate('/assets') } catch (e) { setActionError(e instanceof Error ? e.message : 'Unable to delete asset') }
  }
  const move = async () => {
    if (!moveTo) return
    try { await api.moveAsset(systemId, Number(moveTo)); setMoveTo(''); refresh() } catch (e) { setActionError(e instanceof Error ? e.message : 'Unable to record movement') }
  }

  const a = asset.value
  if (asset.error) return <><Link to="/assets" className="text-sm text-blue-700">← Assets</Link><ErrorMessage error={asset.error} /></>
  if (!a) return <p className="text-sm text-slate-500">Loading…</p>

  const fieldLabels = Object.fromEntries(customFields.map(field => [field.key, field.label]))
  const metadata = Object.entries(a.metadata ?? {})

  return <>
    <Link to="/assets" className="text-sm text-blue-700">← Assets</Link>
    <div className="mt-2 flex flex-wrap items-start justify-between gap-4">
      <div><h2 className="text-2xl font-bold">{a.name}</h2><p className="mt-1 text-sm text-slate-500">{a.asset_type ?? 'Untyped'} · <StatusBadge status={a.status} /></p></div>
      <div className="flex gap-2">
        <button className="rounded border bg-white px-4 py-2 text-sm" onClick={() => setEditing(e => !e)}>{editing ? 'Close editor' : 'Edit'}</button>
        <button className="rounded border border-red-200 bg-white px-4 py-2 text-sm text-red-700" onClick={remove}>Delete</button>
      </div>
    </div>
    <ErrorMessage error={actionError} />

    {editing && <div className="mt-5 rounded-lg border bg-white p-5"><AssetForm asset={a} customFields={customFields} onCancel={() => setEditing(false)}
      onSubmit={async input => { await api.updateAsset(systemId, input); setEditing(false); refresh() }} /></div>}

    <div className="mt-6 grid gap-6 lg:grid-cols-2">
      <Section title="Identity">
        <dl className="grid grid-cols-3 gap-y-2 text-sm">
          <dt className="text-slate-500">System ID</dt><dd className="col-span-2 font-mono">{a.system_id}</dd>
          <dt className="text-slate-500">Serial number</dt><dd className="col-span-2 font-mono">{a.serial_number}</dd>
          <dt className="text-slate-500">Last seen</dt><dd className="col-span-2">{formatDate(a.last_seen_at)}</dd>
          <dt className="text-slate-500">Created</dt><dd className="col-span-2">{formatDate(a.created_at)}</dd>
        </dl>
        {a.description && <p className="mt-3 text-sm text-slate-600">{a.description}</p>}
      </Section>

      <Section title="Location">
        <p className="text-sm">{a.current_location ? <><strong>{a.current_location.name}</strong>{a.current_location.type && <span className="text-slate-500"> · {label(a.current_location.type)}</span>}</> : <span className="text-slate-500">No location recorded yet.</span>}</p>
        <div className="mt-3 flex gap-2">
          <select className="flex-1 rounded border p-2 text-sm" value={moveTo} onChange={e => setMoveTo(e.target.value)}>
            <option value="">Move to…</option>{locations.map(location => <option key={location.id} value={location.id}>{location.name}</option>)}
          </select>
          <button className="rounded bg-blue-600 px-4 text-sm text-white disabled:opacity-50" disabled={!moveTo} onClick={move}>Record move</button>
        </div>
      </Section>

      <Section title="Devices & integrations">
        <ul className="divide-y text-sm">{a.devices?.map(device => <li key={device.system_id} className="py-2">
          <div className="flex items-center justify-between gap-2"><span><strong>{device.name}</strong> <span className="font-mono text-xs text-slate-500">{device.serial_number}</span></span><StatusBadge status={device.status} /></div>
          <p className="text-xs text-slate-500">{device.type ?? 'Device'}{device.integration && <> · via {device.integration.name} ({device.integration.type.toUpperCase()}, {device.integration.status})</>} · last seen {formatDate(device.last_seen_at)}</p>
        </li>)}
          {!a.devices?.length && <li className="py-2 text-slate-500">No device bound. Bind RFID tags, GPS trackers or other devices via the Devices API.</li>}</ul>
      </Section>

      <Section title="Custom fields">
        {metadata.length ? <dl className="grid grid-cols-3 gap-y-2 text-sm">{metadata.map(([key, value]) => <div key={key} className="contents"><dt className="text-slate-500">{fieldLabels[key] ?? key}</dt><dd className="col-span-2 break-words">{display(value)}</dd></div>)}</dl>
          : <p className="text-sm text-slate-500">No custom field values.</p>}
      </Section>

      <Section title="Movement history">
        <ErrorMessage error={movements.error} />
        <ul className="divide-y text-sm">{movements.value?.data.map(m => <li key={m.id} className="py-2"><strong>{m.from_location?.name ?? '—'}</strong> → <strong>{m.to_location?.name ?? '—'}</strong>
          <span className="block text-xs text-slate-500">{formatDate(m.occurred_at)} · {m.source ?? 'manual'}</span></li>)}
          {movements.value && !movements.value.data.length && <li className="py-2 text-slate-500">No movements yet.</li>}</ul>
      </Section>

      <Section title="Activity">
        <ErrorMessage error={activity.error} />
        <ul className="divide-y text-sm">{activity.value?.map((entry, i) => <li key={`${entry.kind}-${i}`} className="py-2">
          <span className="font-medium">{entry.kind === 'event' ? entry.type : label(entry.type)}</span>
          <span className="block text-xs text-slate-500">{entry.actor ?? entry.source ?? 'System'} · {formatDate(entry.occurred_at)}</span></li>)}
          {activity.value && !activity.value.length && <li className="py-2 text-slate-500">No activity recorded.</li>}</ul>
      </Section>
    </div>
  </>
}
