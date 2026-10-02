import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, Device } from '../core/api/client'
import { Can } from '../core/permissions/AccessContext'
import { ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../pages/shared'

/**
 * Devices (tags, readers, trackers…) and their bindings to assets:
 * Device → Device Binding → Asset. Swapping broken hardware rebinds a new
 * device; the asset keeps its identity and history.
 */
export function DevicesPage({ api }: { api: ApiClient }) {
  const load = useCallback(async () => {
    const [devices, types, integrations, assets] = await Promise.all([
      api.devices(), api.deviceTypes(), api.integrations().catch(() => ({ data: [] })), api.assets({ per_page: 100, sort: 'name' }),
    ])
    return { devices: devices.data, types: types.data, integrations: integrations.data, assets: assets.data }
  }, [api])
  const { value, error, reload } = useLoad(load)
  const [creating, setCreating] = useState(false)
  const [binding, setBinding] = useState<Device | null>(null)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload() } catch (e) { setActionError(errorText(e)) }
  }
  const integrationName = (id?: number | null) => value?.integrations.find(i => i.id === id)?.name ?? '—'

  return <>
    <PageHeader title="Devices" description={value ? `${value.devices.length} device(s). Bind a device to an asset so its readings update that asset.` : 'Hardware reporting through integrations.'}
      actions={!creating && <Can permission="device.create"><button className={buttonClass} onClick={() => setCreating(true)}>New device</button></Can>} />
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />

    {creating && value && <DeviceForm types={value.types} integrations={value.integrations} onCancel={() => setCreating(false)}
      onSubmit={input => run(async () => { await api.createDevice(input); setCreating(false) }, 'Device registered.')} />}

    {binding && value && <BindForm device={binding} assets={value.assets} onCancel={() => setBinding(null)}
      onSubmit={(assetSystemId, replace) => run(async () => { await api.bindDevice(binding.system_id, assetSystemId, replace); setBinding(null) }, `${binding.name} bound.`)} />}

    <div className="mt-5 overflow-x-auto rounded-lg border bg-white">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Device</th><th className="p-3">Serial</th><th className="p-3">Type</th><th className="p-3">Integration</th><th className="p-3">Status</th><th className="p-3">Bound asset</th><th className="p-3">Last seen</th><th className="p-3"></th></tr></thead>
        <tbody>{value?.devices.map(device => <tr key={device.system_id} className="border-t">
          <td className="p-3"><p>{device.name}</p><p className="font-mono text-xs text-slate-500">{device.system_id}</p></td>
          <td className="p-3 font-mono text-xs">{device.serial_number ?? '—'}</td>
          <td className="p-3">{device.device_type?.name ?? '—'}</td>
          <td className="p-3">{integrationName(device.integration_id)}</td>
          <td className="p-3"><StatusBadge status={device.status} /></td>
          <td className="p-3">{device.current_binding?.asset_system_id
            ? <Link className="text-blue-700 hover:underline" to={`/assets/${device.current_binding.asset_system_id}`}>{device.current_binding.asset_name ?? device.current_binding.asset_system_id}</Link>
            : <span className="text-slate-400">unbound</span>}</td>
          <td className="p-3 text-xs text-slate-500">{formatDate(device.last_seen_at)}</td>
          <td className="whitespace-nowrap p-3 text-right">
            <Can permission="device.manage-bindings">
              <button className={secondaryButtonClass} onClick={() => setBinding(device)}>{device.current_binding ? 'Rebind' : 'Bind'}</button>
              {device.current_binding && <button className="ml-2 text-sm text-slate-600 hover:underline" onClick={() => run(() => api.unbindDevice(device.system_id), `${device.name} unbound.`)}>Unbind</button>}
            </Can>
            <Can permission="device.delete"><button className="ml-2 text-sm text-red-600 hover:underline" onClick={() => confirm(`Delete device ${device.name}?`) && run(() => api.deleteDevice(device.system_id), 'Device deleted.')}>Delete</button></Can>
          </td>
        </tr>)}
          {value && !value.devices.length && <tr><td colSpan={8} className="p-6 text-center text-slate-500">No devices registered.</td></tr>}</tbody>
      </table>
    </div>
  </>
}

function DeviceForm({ types, integrations, onCancel, onSubmit }: {
  types: { id: number; name: string }[]; integrations: { id: number; name: string; type: string }[]
  onCancel: () => void; onSubmit: (input: { name: string; device_type_id: number; integration_id?: number; serial_number?: string }) => void
}) {
  const [form, setForm] = useState({ name: '', serial_number: '', device_type_id: String(types[0]?.id ?? ''), integration_id: '' })
  const submit = (event: FormEvent) => {
    event.preventDefault()
    onSubmit({ name: form.name.trim(), device_type_id: Number(form.device_type_id), serial_number: form.serial_number.trim() || undefined, integration_id: form.integration_id ? Number(form.integration_id) : undefined })
  }
  return <form onSubmit={submit} className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
    <label className="block text-sm">Name<input required className={inputClass} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="TAG 00001" /></label>
    <label className="block text-sm">Hardware serial / tag ID<input className={inputClass} value={form.serial_number} onChange={e => setForm({ ...form, serial_number: e.target.value })} placeholder="TAG-00001" /></label>
    <label className="block text-sm">Device type<select required className={inputClass} value={form.device_type_id} onChange={e => setForm({ ...form, device_type_id: e.target.value })}>{types.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
    <label className="block text-sm">Integration<select className={inputClass} value={form.integration_id} onChange={e => setForm({ ...form, integration_id: e.target.value })}><option value="">None</option>{integrations.map(i => <option key={i.id} value={i.id}>{i.name} ({i.type})</option>)}</select></label>
    <div className="flex gap-2 sm:col-span-2"><button className={buttonClass}>Register device</button><button type="button" className={secondaryButtonClass} onClick={onCancel}>Cancel</button></div>
  </form>
}

function BindForm({ device, assets, onCancel, onSubmit }: { device: Device; assets: { system_id: string; name: string; serial_number: string }[]; onCancel: () => void; onSubmit: (assetSystemId: string, replace: boolean) => void }) {
  const [asset, setAsset] = useState(assets[0]?.system_id ?? '')
  const bound = !!device.current_binding
  return <form onSubmit={e => { e.preventDefault(); onSubmit(asset, bound) }} className="mt-5 flex flex-wrap items-end gap-3 rounded-lg border bg-white p-5">
    <p className="w-full text-sm">Bind <strong>{device.name}</strong> to an asset{bound && <span className="text-amber-700"> — this replaces its binding to {device.current_binding?.asset_name}</span>}.</p>
    <label className="block min-w-64 flex-1 text-sm">Asset<select required className={inputClass} value={asset} onChange={e => setAsset(e.target.value)}>{assets.map(a => <option key={a.system_id} value={a.system_id}>{a.name} · {a.serial_number}</option>)}</select></label>
    <button className={buttonClass}>Bind</button><button type="button" className={secondaryButtonClass} onClick={onCancel}>Cancel</button>
  </form>
}
