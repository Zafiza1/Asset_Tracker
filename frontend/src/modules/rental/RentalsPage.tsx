import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, Rental } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

const statuses = ['reserved', 'active', 'returned', 'cancelled']
const money = (value?: string | null) => value == null ? '—' : Number(value).toLocaleString()

/** Rental module: rent assets to customers and take them back. */
export function RentalsPage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const listLoad = useCallback(() => api.rentals({ page, status, search, overdue: overdue ? 1 : undefined }), [api, page, status, search, overdue])
  const list = useLoad(listLoad)
  const optionsLoad = useCallback(async () => {
    const [customers, assets, locations] = await Promise.all([api.customers({ status: 'active', per_page: 100 }), api.assets({ per_page: 100, sort: 'name' }), api.locations()])
    return { customers: customers.data, assets: assets.data, locations: locations.data }
  }, [api])
  const options = useLoad(optionsLoad)
  const [form, setForm] = useState({ customer_id: '', asset_id: '', reference: '', due_at: '', daily_rate: '', late_fee_per_day: '', checkout: true })
  const [returnTo, setReturnTo] = useState('')
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('rental')) {
    return <><PageHeader title="Rentals" /><Empty>The Rental module is not enabled for this project. Enable Customer and Rental under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run = async (action: () => Promise<unknown>, done: string) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); list.reload(); return true } catch (e) { setActionError(errorText(e)); return false }
  }

  const create = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.createRental({
      customer_id: Number(form.customer_id),
      asset_id: form.asset_id,
      reference: form.reference || undefined,
      due_at: form.due_at ? new Date(form.due_at).toISOString() : undefined,
      daily_rate: form.daily_rate ? Number(form.daily_rate) : undefined,
      late_fee_per_day: form.late_fee_per_day ? Number(form.late_fee_per_day) : undefined,
      checkout: form.checkout,
    }), form.checkout ? 'Asset rented out.' : 'Rental reserved.')
    if (ok) setForm(f => ({ ...f, asset_id: '', reference: '', due_at: '' }))
  }

  const extend = (rental: Rental) => {
    const days = window.prompt('Extend by how many days?', '7')
    if (!days || Number.isNaN(Number(days))) return
    const due = new Date(new Date(rental.due_at).getTime() + Number(days) * 86400000)
    run(() => api.extendRental(rental.id, due.toISOString()), `Due date moved to ${due.toLocaleDateString()}.`)
  }

  const meta = list.value?.meta
  return <>
    <PageHeader title="Rentals" description="Rent assets to customers. Handing over moves the asset to the customer’s site; taking it back computes rented days, days late and amounts for your billing system." />
    <ErrorMessage error={list.error || options.error || actionError} />
    <Notice message={message} tone="success" />

    <Can permission="rental.create"><form onSubmit={create} className="mt-5 grid gap-3 rounded-lg border bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
      <label className="block text-sm">Customer<select required className={inputClass} value={form.customer_id} onChange={e => setForm({ ...form, customer_id: e.target.value })}>
        <option value="">Select…</option>{options.value?.customers.map(c => <option key={c.id} value={c.id}>{c.name} · {c.code}</option>)}
      </select></label>
      <label className="block text-sm">Asset<select required className={inputClass} value={form.asset_id} onChange={e => setForm({ ...form, asset_id: e.target.value })}>
        <option value="">Select…</option>{options.value?.assets.map(a => <option key={a.system_id} value={a.system_id}>{a.name} · {a.serial_number}</option>)}
      </select></label>
      <label className="block text-sm">Due<input type="datetime-local" className={inputClass} value={form.due_at} onChange={e => setForm({ ...form, due_at: e.target.value })} /></label>
      <label className="block text-sm">Reference<input maxLength={100} className={inputClass} value={form.reference} onChange={e => setForm({ ...form, reference: e.target.value })} /></label>
      <label className="block text-sm">Daily rate<input type="number" min={0} step="any" className={inputClass} value={form.daily_rate} onChange={e => setForm({ ...form, daily_rate: e.target.value })} /></label>
      <label className="block text-sm">Late fee per day<input type="number" min={0} step="any" className={inputClass} value={form.late_fee_per_day} onChange={e => setForm({ ...form, late_fee_per_day: e.target.value })} /></label>
      <label className="flex items-end gap-2 text-sm"><input type="checkbox" checked={form.checkout} onChange={e => setForm({ ...form, checkout: e.target.checked })} /> Hand over now</label>
      <div className="flex items-end"><button className={buttonClass}>{form.checkout ? 'Rent out' : 'Reserve'}</button></div>
      <p className="text-xs text-slate-500 sm:col-span-2 lg:col-span-4">Leave the due date empty to use the project’s default rental period.</p>
    </form></Can>

    <div className="mt-5 flex flex-wrap items-center gap-3 text-sm">
      <input aria-label="Search" placeholder="Search reference, customer or asset…" className="w-64 rounded border p-2" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      <select aria-label="Status" className="rounded border p-2" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option>{statuses.map(s => <option key={s} value={s}>{s}</option>)}
      </select>
      <label className="flex items-center gap-2"><input type="checkbox" checked={overdue} onChange={e => { setOverdue(e.target.checked); setPage(1) }} /> Overdue only</label>
      <Can permission="rental.update"><label className="ml-auto flex items-center gap-2">Take back to<select className="rounded border p-2" value={returnTo} onChange={e => setReturnTo(e.target.value)}>
        <option value="">Select a location…</option>{options.value?.locations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select></label></Can>
    </div>

    {list.value && !list.value.data.length && <div className="mt-3"><Empty>No rentals match.</Empty></div>}
    {!!list.value?.data.length && <div className="mt-3 overflow-x-auto rounded-lg border bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500"><tr>
          <th className="p-3">Asset</th><th className="p-3">Customer</th><th className="p-3">Period</th><th className="p-3">Status</th><th className="p-3 text-right">Amount</th><th className="p-3" />
        </tr></thead>
        <tbody className="divide-y">{list.value.data.map(rental => <tr key={rental.id}>
          <td className="p-3">{rental.asset ? <Link className="text-blue-700 hover:underline" to={`/assets/${rental.asset.system_id}`}>{rental.asset.name}</Link> : '—'}<div className="text-xs text-slate-500">{rental.asset?.serial_number}{rental.reference ? ` · ${rental.reference}` : ''}</div></td>
          <td className="p-3">{rental.customer?.name ?? '—'}<div className="text-xs text-slate-500">{rental.destination?.name ?? 'no site'}</div></td>
          <td className="p-3 text-xs text-slate-600">{formatDate(rental.checked_out_at ?? rental.starts_at)}<br />due {formatDate(rental.due_at)}{rental.returned_at && <><br />back {formatDate(rental.returned_at)}</>}</td>
          <td className="p-3"><StatusBadge status={rental.overdue ? 'overdue' : rental.status} />{!!rental.days_late && <div className="mt-1 text-xs text-red-700">{rental.days_late} days late</div>}</td>
          <td className="p-3 text-right">{rental.status === 'returned' ? <>{money(rental.rental_amount)}{rental.late_fee != null && Number(rental.late_fee) > 0 && <div className="text-xs text-red-700">+ {money(rental.late_fee)} late fee</div>}<div className="text-xs text-slate-500">{rental.rented_days} days</div></> : rental.daily_rate ? <span className="text-xs text-slate-500">{money(rental.daily_rate)}/day</span> : '—'}</td>
          <td className="whitespace-nowrap p-3 text-right"><Can permission="rental.update"><span className="inline-flex gap-2">
            {rental.status === 'reserved' && <>
              <button className={secondaryButtonClass} onClick={() => run(() => api.checkoutRental(rental.id), 'Asset rented out.')}>Hand over</button>
              <button className={secondaryButtonClass} onClick={() => { const reason = window.prompt('Reason for cancelling (optional)'); if (reason !== null) run(() => api.cancelRental(rental.id, reason || undefined), 'Rental cancelled.') }}>Cancel</button>
            </>}
            {(rental.status === 'reserved' || rental.status === 'active') && <button className={secondaryButtonClass} onClick={() => extend(rental)}>Extend</button>}
            {rental.status === 'active' && <button disabled={!returnTo} title={returnTo ? undefined : 'Choose “Take back to” above'} className={secondaryButtonClass} onClick={() => run(() => api.returnRental(rental.id, Number(returnTo)), 'Asset taken back.')}>Take back</button>}
          </span></Can></td>
        </tr>)}</tbody>
      </table>
    </div>}

    {meta && meta.last_page > 1 && <div className="mt-3 flex items-center gap-3 text-sm">
      <button className={secondaryButtonClass} disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <button className={secondaryButtonClass} disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>}
  </>
}
