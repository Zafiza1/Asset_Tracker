import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, Customer, CustomerInput } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

const blank: CustomerInput = { code: '', name: '', contact_name: '', email: '', phone: '', address: '', location_id: null }

/** Customer module: customers/parties that hold or receive assets, with their delivery site. */
export function CustomersPage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const listLoad = useCallback(() => api.customers({ page, search, status }), [api, page, search, status])
  const list = useLoad(listLoad)
  const locationsLoad = useCallback(() => api.locations(), [api])
  const locations = useLoad(locationsLoad)
  const [form, setForm] = useState<CustomerInput>(blank)
  const [editing, setEditing] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('customer')) {
    return <><PageHeader title="Customers" /><Empty>The Customer module is not enabled for this project. Enable it under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run = async (action: () => Promise<unknown>, done: string) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); list.reload(); return true } catch (e) { setActionError(errorText(e)); return false }
  }

  const save = async (event: FormEvent) => {
    event.preventDefault()
    // Empty optional fields are sent as null so an edit can clear them.
    const input = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value])) as CustomerInput
    const ok = await run(() => editing ? api.updateCustomer(editing, input) : api.createCustomer(input), editing ? 'Customer updated.' : 'Customer added.')
    if (ok) { setForm(blank); setEditing(null) }
  }

  const edit = (customer: Customer) => {
    setEditing(customer.id)
    setForm({ code: customer.code, name: customer.name, contact_name: customer.contact_name ?? '', email: customer.email ?? '', phone: customer.phone ?? '', address: customer.address ?? '', location_id: customer.location_id ?? null })
  }

  const remove = (customer: Customer) => {
    if (window.confirm(`Delete customer “${customer.name}” (${customer.code})? Its code can then be reused.`)) run(() => api.deleteCustomer(customer.id), 'Customer deleted.')
  }

  const field = (key: keyof CustomerInput, label: string, props: { required?: boolean; type?: string; maxLength?: number } = {}) =>
    <label className="block text-sm">{label}<input className={inputClass} value={(form[key] as string | undefined) ?? ''} onChange={e => setForm({ ...form, [key]: e.target.value })} {...props} /></label>

  const meta = list.value?.meta
  return <>
    <PageHeader title="Customers" description="Customers and other parties that hold or receive assets. A customer’s site is a project location, so deliveries move assets there." />
    <ErrorMessage error={list.error || locations.error || actionError} />
    <Notice message={message} tone="success" />

    <Can permission={editing ? 'customer.update' : 'customer.create'}><form onSubmit={save} className="mt-5 grid gap-3 rounded-lg border bg-white p-5 sm:grid-cols-2 lg:grid-cols-3">
      {field('code', 'Code', { required: true, maxLength: 100 })}
      {field('name', 'Name', { required: true, maxLength: 255 })}
      {field('contact_name', 'Contact person', { maxLength: 255 })}
      {field('email', 'Email', { type: 'email', maxLength: 255 })}
      {field('phone', 'Phone', { maxLength: 50 })}
      <label className="block text-sm">Site (location)<select className={inputClass} value={form.location_id ?? ''} onChange={e => setForm({ ...form, location_id: e.target.value ? Number(e.target.value) : null })}>
        <option value="">No site</option>
        {locations.value?.data.map(l => <option key={l.id} value={l.id}>{l.name}{l.type ? ` · ${l.type}` : ''}</option>)}
      </select></label>
      <label className="block text-sm sm:col-span-2 lg:col-span-3">Address<input className={inputClass} maxLength={2000} value={form.address ?? ''} onChange={e => setForm({ ...form, address: e.target.value })} /></label>
      <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
        <button className={buttonClass}>{editing ? 'Save changes' : 'Add customer'}</button>
        {editing && <button type="button" className={secondaryButtonClass} onClick={() => { setEditing(null); setForm(blank) }}>Cancel</button>}
      </div>
    </form></Can>

    <div className="mt-5 flex flex-wrap items-center gap-3 text-sm">
      <input aria-label="Search" placeholder="Search name, code or contact…" className="w-64 rounded border p-2" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      <select aria-label="Status" className="rounded border p-2" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </select>
    </div>

    {list.value && !list.value.data.length && <div className="mt-3"><Empty>No customers match.</Empty></div>}
    {!!list.value?.data.length && <div className="mt-3 overflow-x-auto rounded-lg border bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500"><tr>
          <th className="p-3">Customer</th><th className="p-3">Contact</th><th className="p-3">Site</th><th className="p-3">Status</th><th className="p-3" />
        </tr></thead>
        <tbody className="divide-y">{list.value.data.map(customer => <tr key={customer.id}>
          <td className="p-3"><p className="font-medium">{customer.name}</p><p className="text-xs text-slate-500">{customer.code}</p></td>
          <td className="p-3 text-slate-600">{[customer.contact_name, customer.email, customer.phone].filter(Boolean).join(' · ') || '—'}</td>
          <td className="p-3 text-slate-600">{customer.location?.name ?? '—'}</td>
          <td className="p-3"><StatusBadge status={customer.status} /></td>
          <td className="whitespace-nowrap p-3 text-right"><span className="inline-flex gap-2">
            <Can permission="customer.update">
              <button className={secondaryButtonClass} onClick={() => edit(customer)}>Edit</button>
              <button className={secondaryButtonClass} onClick={() => run(() => api.updateCustomer(customer.id, { status: customer.status === 'active' ? 'inactive' : 'active' }), 'Status changed.')}>{customer.status === 'active' ? 'Deactivate' : 'Activate'}</button>
            </Can>
            <Can permission="customer.delete"><button className={secondaryButtonClass} onClick={() => remove(customer)}>Delete</button></Can>
          </span></td>
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
