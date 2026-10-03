import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, MaintenanceRecord } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

const statuses = ['scheduled', 'in_progress', 'completed', 'cancelled']

/** Maintenance module: schedule and record maintenance work on assets. */
export function MaintenancePage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)
  const [page, setPage] = useState(1)
  const listLoad = useCallback(() => api.maintenance({ page, status, overdue: overdue ? 1 : undefined }), [api, page, status, overdue])
  const list = useLoad(listLoad)
  const assetsLoad = useCallback(() => api.assets({ per_page: 100, sort: 'name' }), [api])
  const assets = useLoad(assetsLoad)
  const [form, setForm] = useState({ asset_id: '', title: '', type: 'preventive', scheduled_at: '' })
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('maintenance')) {
    return <><PageHeader title="Maintenance" /><Empty>The Maintenance module is not enabled for this project. Enable it under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run = async (action: () => Promise<unknown>, done: string) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); list.reload() } catch (e) { setActionError(errorText(e)) }
  }

  const schedule = (event: FormEvent) => {
    event.preventDefault()
    run(async () => {
      await api.createMaintenance({ ...form, scheduled_at: form.scheduled_at ? new Date(form.scheduled_at).toISOString() : undefined })
      setForm(f => ({ ...f, title: '', scheduled_at: '' }))
    }, 'Maintenance scheduled.')
  }

  const complete = (record: MaintenanceRecord) => {
    const notes = window.prompt('Completion notes (optional)') ?? undefined
    run(() => api.completeMaintenance(record.id, notes ? { notes } : {}), `“${record.title}” completed.`)
  }

  const meta = list.value?.meta
  return <>
    <PageHeader title="Maintenance" description="Scheduled and completed maintenance work per asset. Completing a job can auto-schedule the next one (module setting)." />
    <ErrorMessage error={list.error || assets.error || actionError} />
    <Notice message={message} tone="success" />

    <Can permission="maintenance.create"><form onSubmit={schedule} className="mt-5 grid gap-3 rounded-lg border bg-white p-5 sm:grid-cols-2 lg:grid-cols-5">
      <label className="block text-sm lg:col-span-2">Asset<select required className={inputClass} value={form.asset_id} onChange={e => setForm({ ...form, asset_id: e.target.value })}>
        <option value="">Select an asset…</option>
        {assets.value?.data.map(a => <option key={a.system_id} value={a.system_id}>{a.name} · {a.serial_number}</option>)}
      </select></label>
      <label className="block text-sm">Title<input required maxLength={255} className={inputClass} value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} /></label>
      <label className="block text-sm">Type<input maxLength={50} className={inputClass} value={form.type} onChange={e => setForm({ ...form, type: e.target.value })} /></label>
      <label className="block text-sm">Scheduled for<input type="datetime-local" className={inputClass} value={form.scheduled_at} onChange={e => setForm({ ...form, scheduled_at: e.target.value })} /></label>
      <div className="flex items-end sm:col-span-2 lg:col-span-5"><button className={buttonClass}>Schedule maintenance</button><span className="ml-3 text-xs text-slate-500">Leave the date empty to use the project’s default interval.</span></div>
    </form></Can>

    <div className="mt-5 flex flex-wrap items-center gap-3 text-sm">
      <select aria-label="Status" className="rounded border p-2" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option>
        {statuses.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
      </select>
      <label className="flex items-center gap-2"><input type="checkbox" checked={overdue} onChange={e => { setOverdue(e.target.checked); setPage(1) }} /> Overdue only</label>
    </div>

    {list.value && !list.value.data.length && <div className="mt-3"><Empty>No maintenance records match.</Empty></div>}
    {!!list.value?.data.length && <div className="mt-3 overflow-x-auto rounded-lg border bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500"><tr>
          <th className="p-3">Asset</th><th className="p-3">Work</th><th className="p-3">Status</th><th className="p-3">Scheduled</th><th className="p-3">Completed</th><th className="p-3" />
        </tr></thead>
        <tbody className="divide-y">{list.value.data.map(record => <tr key={record.id}>
          <td className="p-3">{record.asset ? <Link className="text-blue-700 hover:underline" to={`/assets/${record.asset.system_id}`}>{record.asset.name}</Link> : '—'}<div className="text-xs text-slate-500">{record.asset?.serial_number}</div></td>
          <td className="p-3"><p className="font-medium">{record.title}</p><p className="text-xs text-slate-500">{record.type}{record.notes ? ` · ${record.notes}` : ''}</p></td>
          <td className="p-3"><StatusBadge status={record.overdue ? 'overdue' : record.status} /></td>
          <td className="p-3 text-slate-600">{formatDate(record.scheduled_at)}</td>
          <td className="p-3 text-slate-600">{formatDate(record.completed_at)}</td>
          <td className="whitespace-nowrap p-3 text-right">{record.status !== 'completed' && record.status !== 'cancelled' && <span className="inline-flex gap-2">
            {record.status === 'scheduled' && <Can permission="maintenance.update"><button className={secondaryButtonClass} onClick={() => run(() => api.updateMaintenance(record.id, { status: 'in_progress' }), 'Marked in progress.')}>Start</button></Can>}
            <Can permission="maintenance.complete"><button className={secondaryButtonClass} onClick={() => complete(record)}>Complete</button></Can>
            <Can permission="maintenance.update"><button className={secondaryButtonClass} onClick={() => run(() => api.updateMaintenance(record.id, { status: 'cancelled' }), 'Cancelled.')}>Cancel</button></Can>
          </span>}</td>
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
