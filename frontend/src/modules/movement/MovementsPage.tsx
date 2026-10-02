import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient } from '../../core/api/client'
import { Can } from '../../core/permissions/AccessContext'
import { ErrorMessage, Notice, PageHeader, buttonClass, errorText, formatDate, inputClass, useLoad } from '../../pages/shared'

/** Movement history per asset, and manual moves between locations. */
export function MovementsPage({ api }: { api: ApiClient }) {
  const optionsLoad = useCallback(async () => {
    const [assets, locations] = await Promise.all([api.assets({ per_page: 100, sort: 'name' }), api.locations()])
    return { assets: assets.data, locations: locations.data }
  }, [api])
  const options = useLoad(optionsLoad)
  const [assetId, setAssetId] = useState('')
  const selected = assetId || options.value?.assets[0]?.system_id || ''
  const historyLoad = useCallback(() => selected ? api.movements(selected) : Promise.resolve({ data: [] }), [api, selected])
  const history = useLoad(historyLoad)
  const [toLocation, setToLocation] = useState('')
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')
  const asset = options.value?.assets.find(a => a.system_id === selected)

  const move = async (event: FormEvent) => {
    event.preventDefault(); setMessage(''); setActionError('')
    try { await api.moveAsset(selected, Number(toLocation)); setMessage('Movement recorded.'); setToLocation(''); history.reload(); options.reload() } catch (e) { setActionError(errorText(e)) }
  }

  return <>
    <PageHeader title="Movements" description="Every location change is kept as history, whether recorded manually or reported by GPS/RFID." />
    <ErrorMessage error={options.error || history.error || actionError} />
    <Notice message={message} tone="success" />
    <div className="mt-5 grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
      <label className="block text-sm">Asset<select className={inputClass} value={selected} onChange={e => setAssetId(e.target.value)}>
        {options.value?.assets.map(a => <option key={a.system_id} value={a.system_id}>{a.name} · {a.serial_number}</option>)}
      </select></label>
      {asset && <div className="text-sm"><p className="text-slate-500">Current location</p><p className="mt-1 font-medium">{asset.current_location?.name ?? 'Unknown'}</p>
        <Link className="text-xs text-blue-700 hover:underline" to={`/assets/${asset.system_id}`}>Open asset {asset.system_id}</Link></div>}
      <Can permission="movement.create"><form onSubmit={move} className="flex items-end gap-2 sm:col-span-2">
        <label className="block flex-1 text-sm">Move to<select required className={inputClass} value={toLocation} onChange={e => setToLocation(e.target.value)}>
          <option value="">Select a location…</option>{options.value?.locations.filter(l => l.id !== asset?.current_location?.id).map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select></label>
        <button disabled={!selected} className={buttonClass}>Record movement</button>
      </form></Can>
    </div>
    {options.value && !options.value.assets.length && <p className="mt-5 rounded-lg border bg-white p-6 text-sm text-slate-600">No assets in this project yet.</p>}
    {selected && <ul className="mt-5 divide-y rounded-lg border bg-white">
      {history.value?.data.map(m => <li key={m.id} className="flex flex-wrap justify-between gap-2 p-4 text-sm">
        <span><strong>{m.from_location?.name ?? 'Unknown'}</strong> → <strong>{m.to_location?.name ?? 'Unknown'}</strong></span>
        <span className="text-slate-500">{formatDate(m.occurred_at)} · {m.source ?? 'manual'}</span>
      </li>)}
      {history.value && !history.value.data.length && <li className="p-4 text-sm text-slate-500">No movements recorded for this asset.</li>}
    </ul>}
  </>
}
