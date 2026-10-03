import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, CountSummary, InventoryCount } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, Section, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

type Run = (action: () => Promise<unknown>, done: string) => Promise<boolean>

/** Inventory module: stock per location, minimum levels and stock counts. */
export function InventoryPage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [lowOnly, setLowOnly] = useState(false)
  const stockLoad = useCallback(() => api.stock({ low: lowOnly ? 1 : undefined }), [api, lowOnly])
  const stock = useLoad(stockLoad)
  const levelsLoad = useCallback(() => api.inventoryLevels(), [api])
  const levels = useLoad(levelsLoad)
  const locationsLoad = useCallback(() => api.locations(), [api])
  const locations = useLoad(locationsLoad)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('inventory')) {
    return <><PageHeader title="Inventory" /><Empty>The Inventory module is not enabled for this project. Enable it under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run: Run = async (action, done) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); stock.reload(); levels.reload(); return true } catch (e) { setActionError(errorText(e)); return false }
  }

  const rows = stock.value ?? []
  return <>
    <PageHeader title="Inventory" description="Stock is the number of assets whose current location is each location, so it always matches movement history." />
    <ErrorMessage error={stock.error || levels.error || locations.error || actionError} />
    <Notice message={message} tone="success" />

    <div className="mt-5"><Section title="Stock" actions={<label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={lowOnly} onChange={e => setLowOnly(e.target.checked)} /> Low only</label>}>
      {stock.value && !rows.length && <p className="text-sm text-slate-500">{lowOnly ? 'Nothing is below its minimum.' : 'No assets have a location yet.'}</p>}
      {!!rows.length && <div className="overflow-x-auto"><table className="w-full text-left text-sm">
        <thead className="border-b text-xs uppercase text-slate-500"><tr><th className="py-2">Location</th><th className="py-2">Asset type</th><th className="py-2 text-right">Quantity</th><th className="py-2 text-right">Minimum</th><th className="py-2" /></tr></thead>
        <tbody className="divide-y">{rows.map(row => <tr key={`${row.location_id}|${row.all_types ? '*' : row.asset_type ?? ''}`} className={row.all_types ? 'bg-slate-50 font-medium' : ''}>
          <td className="py-2">{row.location_name ?? `#${row.location_id}`}</td>
          <td className="py-2">{row.all_types ? 'All types' : row.asset_type ?? <span className="text-slate-400">untyped</span>}</td>
          <td className="py-2 text-right">{row.quantity}</td>
          <td className="py-2 text-right text-slate-500">{row.min_quantity ?? '—'}</td>
          <td className="py-2 text-right">{row.low && <StatusBadge status="low" />}</td>
        </tr>)}</tbody>
      </table></div>}
    </Section></div>

    <div className="mt-5"><Levels api={api} levels={levels.value ?? []} locations={locations.value?.data ?? []} run={run} /></div>
    <div className="mt-5"><Counts api={api} locations={locations.value?.data ?? []} onChange={() => stock.reload()} /></div>
  </>
}

function Levels({ api, levels, locations, run }: { api: ApiClient; levels: { id: number; location_name?: string | null; asset_type?: string | null; min_quantity: number }[]; locations: { id: number; name: string }[]; run: Run }) {
  const [form, setForm] = useState({ location_id: '', asset_type: '', min_quantity: '' })

  const save = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.setInventoryLevel({ location_id: Number(form.location_id), asset_type: form.asset_type || undefined, min_quantity: Number(form.min_quantity) }), 'Minimum saved.')
    if (ok) setForm({ location_id: '', asset_type: '', min_quantity: '' })
  }

  return <Section title="Minimum levels">
    <p className="text-xs text-slate-500">A location (optionally for one asset type) is low when it has fewer assets than its minimum. Dropping below it sends an <code>inventory.low_stock</code> event. The project’s low stock threshold setting applies to asset types without their own minimum.</p>
    {!!levels.length && <ul className="mt-3 divide-y text-sm">
      {levels.map(level => <li key={level.id} className="flex items-center justify-between gap-2 py-2">
        <span>{level.location_name} · {level.asset_type ?? 'all types'} · at least <strong>{level.min_quantity}</strong></span>
        <Can permission="inventory.adjust"><button className={secondaryButtonClass} onClick={() => run(() => api.deleteInventoryLevel(level.id), 'Minimum removed.')}>Remove</button></Can>
      </li>)}
    </ul>}
    <Can permission="inventory.adjust"><form onSubmit={save} className="mt-3 grid gap-2 border-t pt-3 text-sm sm:grid-cols-4">
      <select required aria-label="Location" className="rounded border p-2" value={form.location_id} onChange={e => setForm({ ...form, location_id: e.target.value })}>
        <option value="">Location…</option>{locations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select>
      <input aria-label="Asset type" placeholder="Asset type (empty = all)" maxLength={255} className="rounded border p-2" value={form.asset_type} onChange={e => setForm({ ...form, asset_type: e.target.value })} />
      <input aria-label="Minimum" required type="number" min={0} placeholder="Minimum" className="rounded border p-2" value={form.min_quantity} onChange={e => setForm({ ...form, min_quantity: e.target.value })} />
      <button className={buttonClass}>Set minimum</button>
    </form></Can>
  </Section>
}

function Counts({ api, locations, onChange }: { api: ApiClient; locations: { id: number; name: string }[]; onChange: () => void }) {
  const listLoad = useCallback(() => api.inventoryCounts(), [api])
  const list = useLoad(listLoad)
  const [locationId, setLocationId] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const [error, setError] = useState('')

  const start = async (event: FormEvent) => {
    event.preventDefault(); setError('')
    try { const count = await api.openInventoryCount(Number(locationId)); setLocationId(''); setOpen(count.id); list.reload() } catch (e) { setError(errorText(e)) }
  }

  return <Section title="Stock counts">
    <ErrorMessage error={list.error || error} />
    <Can permission="inventory.adjust"><form onSubmit={start} className="flex flex-wrap items-end gap-2 text-sm">
      <label className="block">Count a location<select required className={inputClass} value={locationId} onChange={e => setLocationId(e.target.value)}>
        <option value="">Select…</option>{locations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select></label>
      <button className={buttonClass}>Start count</button>
    </form></Can>
    {list.value && !list.value.data.length && <p className="mt-3 text-sm text-slate-500">No stock counts yet.</p>}
    <ul className="mt-3 divide-y text-sm">
      {list.value?.data.map(count => <li key={count.id} className="py-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span><strong>{count.location_name}</strong> <span className="text-xs text-slate-500">· {formatDate(count.created_at)} · <Tally summary={count.summary} /></span></span>
          <span className="flex items-center gap-2"><StatusBadge status={count.status} /><button className={secondaryButtonClass} onClick={() => setOpen(open === count.id ? null : count.id)}>{open === count.id ? 'Hide' : count.status === 'open' ? 'Continue' : 'Details'}</button></span>
        </div>
        {open === count.id && <CountDetail api={api} id={count.id} onChange={() => { list.reload(); onChange() }} />}
      </li>)}
    </ul>
  </Section>
}

function Tally({ summary }: { summary: CountSummary }) {
  return <>{summary.found}/{summary.expected} found · {summary.missing} missing · {summary.unexpected} unexpected</>
}

/** Scan assets into an open count, then complete it; or read a closed one. */
function CountDetail({ api, id, onChange }: { api: ApiClient; id: number; onChange: () => void }) {
  const load = useCallback(() => api.inventoryCount(id), [api, id])
  const detail = useLoad(load)
  const [codes, setCodes] = useState('')
  const [reconcile, setReconcile] = useState(true)
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const count: InventoryCount | null = detail.value
  if (detail.error) return <ErrorMessage error={detail.error} />
  if (!count) return <p className="mt-2 text-slate-500">Loading…</p>

  const act = async (action: () => Promise<unknown>, done: string) => {
    setError(''); setNote('')
    try { await action(); setNote(done); detail.reload(); onChange() } catch (e) { setError(errorText(e)) }
  }

  // One code per line: system IDs (AST-…) or serial numbers, as a scanner types them.
  const scan = (event: FormEvent) => {
    event.preventDefault()
    const lines = [...new Set(codes.split(/\s*[\n,]\s*/).map(c => c.trim()).filter(Boolean))]
    const assetIds = lines.filter(c => c.toUpperCase().startsWith('AST-'))
    const serials = lines.filter(c => !c.toUpperCase().startsWith('AST-'))
    act(async () => {
      const result = await api.scanInventoryCount(id, { asset_ids: assetIds.length ? assetIds : undefined, serial_numbers: serials.length ? serials : undefined })
      setCodes('')
      if (result.unknown.length) setError(`Not assets of this project: ${result.unknown.join(', ')}`)
    }, `${lines.length} codes scanned.`)
  }

  return <div className="mt-2 rounded border bg-slate-50 p-3">
    <ErrorMessage error={error} />
    <Notice message={note} tone="success" />
    {count.status === 'open' && <Can permission="inventory.adjust">
      <form onSubmit={scan} className="flex flex-wrap items-end gap-2">
        <label className="block flex-1 text-xs">Scan or type codes (one per line)<textarea rows={3} className={inputClass} value={codes} onChange={e => setCodes(e.target.value)} placeholder={'AST-…\nSERIAL-001'} /></label>
        <button disabled={!codes.trim()} className={secondaryButtonClass}>Add scans</button>
      </form>
      <div className="mt-2 flex flex-wrap items-center gap-3">
        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={reconcile} onChange={e => setReconcile(e.target.checked)} /> Move unexpected assets here when completing</label>
        <button className={buttonClass} onClick={() => act(() => api.completeInventoryCount(id, reconcile), 'Count completed.')}>Complete count</button>
        <button className={secondaryButtonClass} onClick={() => act(() => api.cancelInventoryCount(id), 'Count cancelled.')}>Cancel</button>
      </div>
    </Can>}
    <ul className="mt-2 divide-y">
      {count.items?.map(item => <li key={item.system_id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
        <span><Link className="text-blue-700 hover:underline" to={`/assets/${item.system_id}`}>{item.name}</Link> <span className="text-xs text-slate-500">{item.serial_number}{item.recorded_location ? ` · recorded at ${item.recorded_location}` : ''}{item.reconciled ? ' · moved here' : ''}</span></span>
        <StatusBadge status={count.status === 'open' && item.outcome === 'missing' ? 'pending' : item.outcome} />
      </li>)}
      {!count.items?.length && <li className="py-1.5 text-slate-500">No assets recorded or scanned at this location.</li>}
    </ul>
  </div>
}
