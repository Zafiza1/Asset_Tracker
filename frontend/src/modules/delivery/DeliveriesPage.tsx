import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, Delivery, Location } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

const statuses = ['pending', 'in_transit', 'delivered', 'returned', 'cancelled']

/** Delivery module: send assets to a customer's site and take them back. */
export function DeliveriesPage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const listLoad = useCallback(() => api.deliveries({ page, status, search }), [api, page, status, search])
  const list = useLoad(listLoad)
  const optionsLoad = useCallback(async () => {
    const [customers, assets, locations] = await Promise.all([api.customers({ status: 'active', per_page: 100 }), api.assets({ per_page: 100, sort: 'name' }), api.locations()])
    return { customers: customers.data, assets: assets.data, locations: locations.data }
  }, [api])
  const options = useLoad(optionsLoad)
  const [form, setForm] = useState({ customer_id: '', reference: '', asset_ids: [] as string[] })
  const [open, setOpen] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('delivery')) {
    return <><PageHeader title="Deliveries" /><Empty>The Delivery module is not enabled for this project. Enable Customer and Delivery under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run = async (action: () => Promise<unknown>, done: string) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); list.reload(); return true } catch (e) { setActionError(errorText(e)); return false }
  }

  const create = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.createDelivery({ customer_id: Number(form.customer_id), asset_ids: form.asset_ids, reference: form.reference || undefined }), 'Delivery created.')
    if (ok) setForm({ customer_id: '', reference: '', asset_ids: [] })
  }

  const toggleAsset = (systemId: string) =>
    setForm(f => ({ ...f, asset_ids: f.asset_ids.includes(systemId) ? f.asset_ids.filter(id => id !== systemId) : [...f.asset_ids, systemId] }))

  const meta = list.value?.meta
  return <>
    <PageHeader title="Deliveries" description="Send assets to a customer’s site and track their return. Every step moves the assets in their movement history." />
    <ErrorMessage error={list.error || options.error || actionError} />
    <Notice message={message} tone="success" />

    <Can permission="delivery.create"><form onSubmit={create} className="mt-5 rounded-lg border bg-white p-5">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">Customer<select required className={inputClass} value={form.customer_id} onChange={e => setForm({ ...form, customer_id: e.target.value })}>
          <option value="">Select a customer…</option>
          {options.value?.customers.map(c => <option key={c.id} value={c.id}>{c.name} · {c.code}{c.location ? ` → ${c.location.name}` : ' (no site)'}</option>)}
        </select></label>
        <label className="block text-sm">Reference (delivery note)<input maxLength={100} className={inputClass} value={form.reference} onChange={e => setForm({ ...form, reference: e.target.value })} /></label>
      </div>
      <p className="mt-3 text-sm">Assets <span className="text-slate-500">({form.asset_ids.length} selected)</span></p>
      <div className="mt-1 grid max-h-48 gap-1 overflow-y-auto rounded border p-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
        {options.value?.assets.map(a => <label key={a.system_id} className="flex items-center gap-2">
          <input type="checkbox" checked={form.asset_ids.includes(a.system_id)} onChange={() => toggleAsset(a.system_id)} />
          <span>{a.name} <span className="text-xs text-slate-500">{a.serial_number}{a.current_location ? ` · ${a.current_location.name}` : ''}</span></span>
        </label>)}
      </div>
      <button disabled={!form.asset_ids.length} className={`mt-3 ${buttonClass}`}>Create delivery</button>
    </form></Can>

    <div className="mt-5 flex flex-wrap items-center gap-3 text-sm">
      <input aria-label="Search" placeholder="Search reference or customer…" className="w-64 rounded border p-2" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      <select aria-label="Status" className="rounded border p-2" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option>
        {statuses.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
      </select>
    </div>

    {list.value && !list.value.data.length && <div className="mt-3"><Empty>No deliveries match.</Empty></div>}
    {!!list.value?.data.length && <ul className="mt-3 divide-y rounded-lg border bg-white">
      {list.value.data.map(delivery => <li key={delivery.id} className="p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="font-medium">{delivery.reference ?? `Delivery #${delivery.id}`} <span className="font-normal text-slate-500">→ {delivery.customer?.name ?? '—'}</span></p>
            <p className="text-xs text-slate-500">{delivery.items_count ?? 0} assets · to {delivery.destination?.name ?? 'customer site not set'} · created {formatDate(delivery.created_at)}</p>
          </div>
          <div className="flex items-center gap-3">
            <StatusBadge status={delivery.status} />
            <button className={secondaryButtonClass} onClick={() => setOpen(open === delivery.id ? null : delivery.id)}>{open === delivery.id ? 'Hide' : 'Details'}</button>
          </div>
        </div>
        {open === delivery.id && <DeliveryDetail api={api} id={delivery.id} locations={options.value?.locations ?? []} run={run} />}
      </li>)}
    </ul>}

    {meta && meta.last_page > 1 && <div className="mt-3 flex items-center gap-3 text-sm">
      <button className={secondaryButtonClass} disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <button className={secondaryButtonClass} disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>}
  </>
}

/** Items and the next steps for one delivery. */
function DeliveryDetail({ api, id, locations, run }: { api: ApiClient; id: number; locations: Location[]; run: (action: () => Promise<unknown>, done: string) => Promise<boolean> }) {
  const load = useCallback(() => api.delivery(id), [api, id])
  const detail = useLoad(load)
  const [locationId, setLocationId] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const delivery: Delivery | null = detail.value
  if (detail.error) return <ErrorMessage error={detail.error} />
  if (!delivery) return <p className="mt-3 text-slate-500">Loading…</p>

  const step = async (action: () => Promise<unknown>, done: string) => { if (await run(action, done)) { detail.reload(); setSelected([]) } }
  const outstanding = (delivery.items ?? []).filter(item => item.status === 'delivered')

  return <div className="mt-3 rounded border bg-slate-50 p-3">
    <ul className="divide-y">
      {delivery.items?.map(item => <li key={item.system_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
        <span className="flex items-center gap-2">
          {delivery.status === 'delivered' && item.status === 'delivered' && <input type="checkbox" aria-label={`Return ${item.serial_number}`} checked={selected.includes(item.system_id)} onChange={() => setSelected(s => s.includes(item.system_id) ? s.filter(x => x !== item.system_id) : [...s, item.system_id])} />}
          <Link className="text-blue-700 hover:underline" to={`/assets/${item.system_id}`}>{item.name}</Link>
          <span className="text-xs text-slate-500">{item.serial_number}</span>
        </span>
        <span className="flex items-center gap-2 text-xs text-slate-500"><StatusBadge status={item.status} />{item.returned_at ? `returned ${formatDate(item.returned_at)}` : item.delivered_at ? `delivered ${formatDate(item.delivered_at)}` : ''}</span>
      </li>)}
    </ul>
    {delivery.received_by && <p className="mt-2 text-xs text-slate-500">Received by {delivery.received_by} on {formatDate(delivery.delivered_at)}</p>}
    {delivery.notes && <p className="mt-1 whitespace-pre-line text-xs text-slate-500">{delivery.notes}</p>}

    <Can permission="delivery.update">{['pending', 'in_transit', 'delivered'].includes(delivery.status) && <div className="mt-3 flex flex-wrap items-end gap-2">
      {delivery.status !== 'in_transit' && <label className="block text-xs">{delivery.status === 'pending' ? 'Via (optional, e.g. truck)' : 'Return to'}
        <select className={inputClass} value={locationId} onChange={e => setLocationId(e.target.value)}>
          <option value="">{delivery.status === 'pending' ? 'Direct' : 'Select a location…'}</option>
          {locations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select></label>}
      {delivery.status === 'pending' && <button className={secondaryButtonClass} onClick={() => step(() => api.dispatchDelivery(id, locationId ? Number(locationId) : undefined), 'Delivery dispatched.')}>Dispatch</button>}
      {delivery.status !== 'delivered' && <>
        <button className={secondaryButtonClass} onClick={() => { const by = window.prompt('Received by (name)'); if (by !== null) step(() => api.deliverDelivery(id, by || undefined), 'Delivery completed.') }}>Mark delivered</button>
        <button className={secondaryButtonClass} onClick={() => { const reason = window.prompt('Reason for cancelling (optional)'); if (reason !== null) step(() => api.cancelDelivery(id, reason || undefined), 'Delivery cancelled.') }}>Cancel</button>
      </>}
      {delivery.status === 'delivered' && <button disabled={!locationId} className={secondaryButtonClass} onClick={() => step(() => api.returnDelivery(id, Number(locationId), selected.length ? selected : undefined), 'Assets returned.')}>
        {selected.length ? `Return ${selected.length} selected` : `Return all ${outstanding.length}`}
      </button>}
    </div>}</Can>
  </div>
}
