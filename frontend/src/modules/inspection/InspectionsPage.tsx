import { useCallback, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient, ChecklistItem, Inspection, InspectionAnswers } from '../../core/api/client'
import { Can, useAccess } from '../../core/permissions/AccessContext'
import { Empty, ErrorMessage, Notice, PageHeader, Section, StatusBadge, buttonClass, errorText, formatDate, inputClass, secondaryButtonClass, useLoad } from '../../pages/shared'

type Run = (action: () => Promise<unknown>, done: string) => Promise<boolean>

/** Inspection module: checklist-based inspections of assets. */
export function InspectionsPage({ api }: { api: ApiClient }) {
  const { hasModule, ready } = useAccess()
  const [status, setStatus] = useState('')
  const [result, setResult] = useState('')
  const [overdue, setOverdue] = useState(false)
  const [page, setPage] = useState(1)
  const listLoad = useCallback(() => api.inspections({ page, status, result, overdue: overdue ? 1 : undefined }), [api, page, status, result, overdue])
  const list = useLoad(listLoad)
  const optionsLoad = useCallback(async () => {
    const [assets, checklists] = await Promise.all([api.assets({ per_page: 100, sort: 'name' }), api.inspectionChecklists()])
    return { assets: assets.data, checklists }
  }, [api])
  const options = useLoad(optionsLoad)
  const [form, setForm] = useState({ asset_id: '', checklist_id: '', scheduled_at: '' })
  const [open, setOpen] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  if (ready && !hasModule('inspection')) {
    return <><PageHeader title="Inspections" /><Empty>The Inspection module is not enabled for this project. Enable it under <Link className="text-blue-700 hover:underline" to="/modules">Modules</Link>.</Empty></>
  }

  const run: Run = async (action, done) => {
    setMessage(''); setActionError('')
    try { await action(); setMessage(done); list.reload(); return true } catch (e) { setActionError(errorText(e)); return false }
  }

  const asset = options.value?.assets.find(a => a.system_id === form.asset_id)
  const usableChecklists = (options.value?.checklists ?? []).filter(c => c.active && (!c.asset_type || c.asset_type === asset?.asset_type))

  const schedule = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.scheduleInspection({
      asset_id: form.asset_id,
      checklist_id: form.checklist_id ? Number(form.checklist_id) : undefined,
      scheduled_at: form.scheduled_at ? new Date(form.scheduled_at).toISOString() : undefined,
    }), 'Inspection scheduled.')
    if (ok) setForm({ asset_id: '', checklist_id: '', scheduled_at: '' })
  }

  const meta = list.value?.meta
  return <>
    <PageHeader title="Inspections" description="Checklist-based inspections. A failed check marks the inspection as failed; the next one is due after the project’s interval." />
    <ErrorMessage error={list.error || options.error || actionError} />
    <Notice message={message} tone="success" />

    <Can permission="inspection.create"><form onSubmit={schedule} className="mt-5 grid gap-3 rounded-lg border bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
      <label className="block text-sm">Asset<select required className={inputClass} value={form.asset_id} onChange={e => setForm({ ...form, asset_id: e.target.value, checklist_id: '' })}>
        <option value="">Select an asset…</option>
        {options.value?.assets.map(a => <option key={a.system_id} value={a.system_id}>{a.name} · {a.serial_number}</option>)}
      </select></label>
      <label className="block text-sm">Checklist<select className={inputClass} value={form.checklist_id} onChange={e => setForm({ ...form, checklist_id: e.target.value })}>
        <option value="">Automatic (for this asset type)</option>
        {usableChecklists.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
      </select></label>
      <label className="block text-sm">Due<input type="datetime-local" className={inputClass} value={form.scheduled_at} onChange={e => setForm({ ...form, scheduled_at: e.target.value })} /></label>
      <div className="flex items-end"><button className={buttonClass}>Schedule inspection</button></div>
    </form></Can>

    <div className="mt-5 flex flex-wrap items-center gap-3 text-sm">
      <select aria-label="Status" className="rounded border p-2" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
      </select>
      <select aria-label="Result" className="rounded border p-2" value={result} onChange={e => { setResult(e.target.value); setPage(1) }}>
        <option value="">Any result</option><option value="pass">Pass</option><option value="fail">Fail</option>
      </select>
      <label className="flex items-center gap-2"><input type="checkbox" checked={overdue} onChange={e => { setOverdue(e.target.checked); setPage(1) }} /> Overdue only</label>
    </div>

    {list.value && !list.value.data.length && <div className="mt-3"><Empty>No inspections match.</Empty></div>}
    {!!list.value?.data.length && <ul className="mt-3 divide-y rounded-lg border bg-white">
      {list.value.data.map(inspection => <li key={inspection.id} className="p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="font-medium">{inspection.asset ? <Link className="text-blue-700 hover:underline" to={`/assets/${inspection.asset.system_id}`}>{inspection.asset.name}</Link> : '—'} <span className="font-normal text-slate-500">· {inspection.checklist?.name ?? 'No checklist'}</span></p>
            <p className="text-xs text-slate-500">{inspection.status === 'completed' ? `Performed ${formatDate(inspection.performed_at)} · next due ${formatDate(inspection.next_due_at)}` : `Due ${formatDate(inspection.scheduled_at)}`}</p>
          </div>
          <div className="flex items-center gap-2">
            {inspection.result && <StatusBadge status={inspection.result} />}
            <StatusBadge status={inspection.overdue ? 'overdue' : inspection.status} />
            <button className={secondaryButtonClass} onClick={() => setOpen(open === inspection.id ? null : inspection.id)}>{open === inspection.id ? 'Hide' : inspection.status === 'scheduled' ? 'Open' : 'Details'}</button>
          </div>
        </div>
        {open === inspection.id && <InspectionDetail api={api} id={inspection.id} run={run} onDone={() => setOpen(null)} />}
      </li>)}
    </ul>}

    {meta && meta.last_page > 1 && <div className="mt-3 flex items-center gap-3 text-sm">
      <button className={secondaryButtonClass} disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <button className={secondaryButtonClass} disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>}

    <div className="mt-6"><Checklists api={api} checklists={options.value?.checklists ?? []} run={async (action, done) => { const ok = await run(action, done); if (ok) options.reload(); return ok }} /></div>
  </>
}

/** Fill in a scheduled inspection, or read a recorded one. */
function InspectionDetail({ api, id, run, onDone }: { api: ApiClient; id: number; run: Run; onDone: () => void }) {
  const load = useCallback(() => api.inspection(id), [api, id])
  const detail = useLoad(load)
  const [answers, setAnswers] = useState<InspectionAnswers>({})
  const [result, setResult] = useState('')
  const [notes, setNotes] = useState('')
  const inspection: Inspection | null = detail.value
  if (detail.error) return <ErrorMessage error={detail.error} />
  if (!inspection) return <p className="mt-3 text-slate-500">Loading…</p>

  if (inspection.status !== 'scheduled') {
    return <div className="mt-3 rounded border bg-slate-50 p-3">
      {inspection.checklist_snapshot?.length ? <ul className="divide-y">
        {inspection.checklist_snapshot.map(item => <li key={item.key} className="flex justify-between gap-3 py-1.5">
          <span>{item.label}</span><Answer item={item} value={inspection.answers?.[item.key]} />
        </li>)}
      </ul> : <p className="text-slate-500">No checklist.</p>}
      {inspection.notes && <p className="mt-2 whitespace-pre-line text-xs text-slate-500">{inspection.notes}</p>}
    </div>
  }

  const items = inspection.checklist?.items ?? []
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.recordInspection(id, { answers: items.length ? answers : undefined, result: items.length ? undefined : result, notes: notes || undefined }), 'Inspection recorded.')
    if (ok) onDone()
  }

  return <div className="mt-3 rounded border bg-slate-50 p-3">
    <Can permission="inspection.create"><form onSubmit={submit} className="space-y-2">
      {items.map(item => <div key={item.key} className="flex flex-wrap items-center justify-between gap-2">
        <span>{item.label}{item.required && <span className="text-red-600"> *</span>}</span>
        {item.type === 'pass_fail' && <span className="flex gap-1">
          {[true, false].map(value => <button type="button" key={String(value)} onClick={() => setAnswers({ ...answers, [item.key]: value })}
            className={`rounded border px-3 py-1 text-xs ${answers[item.key] === value ? (value ? 'border-green-600 bg-green-600 text-white' : 'border-red-600 bg-red-600 text-white') : 'bg-white'}`}>{value ? 'Pass' : 'Fail'}</button>)}
        </span>}
        {item.type === 'number' && <input type="number" step="any" required={item.required} className="w-32 rounded border p-1 text-sm" value={answers[item.key] === undefined ? '' : String(answers[item.key])}
          onChange={e => { const next = { ...answers }; if (e.target.value === '') delete next[item.key]; else next[item.key] = Number(e.target.value); setAnswers(next) }} />}
        {item.type === 'text' && <input required={item.required} maxLength={2000} className="w-64 rounded border p-1 text-sm" value={String(answers[item.key] ?? '')}
          onChange={e => setAnswers({ ...answers, [item.key]: e.target.value })} />}
      </div>)}
      {!items.length && <label className="block">Result<select required className={inputClass} value={result} onChange={e => setResult(e.target.value)}>
        <option value="">Select…</option><option value="pass">Pass</option><option value="fail">Fail</option>
      </select></label>}
      <label className="block">Notes<input className={inputClass} maxLength={5000} value={notes} onChange={e => setNotes(e.target.value)} /></label>
      <div className="flex gap-2">
        <button className={buttonClass}>Record result</button>
        <Can permission="inspection.update"><button type="button" className={secondaryButtonClass} onClick={async () => { if (await run(() => api.cancelInspection(id), 'Inspection cancelled.')) onDone() }}>Cancel inspection</button></Can>
      </div>
    </form></Can>
  </div>
}

function Answer({ item, value }: { item: ChecklistItem; value: unknown }) {
  if (value === undefined || value === null || value === '') return <span className="text-slate-400">—</span>
  if (item.type === 'pass_fail') return <StatusBadge status={value ? 'pass' : 'fail'} />
  return <span className="text-slate-700">{String(value)}</span>
}

const blankItem = (): ChecklistItem => ({ key: '', label: '', type: 'pass_fail', required: true })
const keyFrom = (label: string) => label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'c_$1')

/** The project's checklists; managers create and (de)activate them. */
function Checklists({ api, checklists, run }: { api: ApiClient; checklists: { id: number; name: string; asset_type?: string | null; items: ChecklistItem[]; active: boolean }[]; run: Run }) {
  const [name, setName] = useState('')
  const [assetType, setAssetType] = useState('')
  const [items, setItems] = useState<ChecklistItem[]>([blankItem()])

  const save = async (event: FormEvent) => {
    event.preventDefault()
    const ok = await run(() => api.createInspectionChecklist({ name, asset_type: assetType || undefined, items: items.map(i => ({ ...i, key: i.key || keyFrom(i.label) })) }), 'Checklist created.')
    if (ok) { setName(''); setAssetType(''); setItems([blankItem()]) }
  }
  const setItem = (index: number, patch: Partial<ChecklistItem>) => setItems(list => list.map((item, i) => i === index ? { ...item, ...patch } : item))

  return <Section title="Checklists">
    {!checklists.length && <p className="text-sm text-slate-500">No checklists yet.</p>}
    <ul className="divide-y text-sm">
      {checklists.map(c => <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
        <span><strong>{c.name}</strong> <span className="text-xs text-slate-500">{c.asset_type ? `${c.asset_type} assets` : 'any asset'} · {c.items.length} checks</span></span>
        <span className="flex items-center gap-2"><StatusBadge status={c.active ? 'active' : 'inactive'} />
          <Can permission="inspection.update"><button className={secondaryButtonClass} onClick={() => run(() => api.updateInspectionChecklist(c.id, { active: !c.active }), c.active ? 'Checklist deactivated.' : 'Checklist activated.')}>{c.active ? 'Deactivate' : 'Activate'}</button></Can>
        </span>
      </li>)}
    </ul>
    <Can permission="inspection.update"><form onSubmit={save} className="mt-4 space-y-2 border-t pt-4 text-sm">
      <div className="grid gap-2 sm:grid-cols-2">
        <label className="block">Name<input required maxLength={255} className={inputClass} value={name} onChange={e => setName(e.target.value)} /></label>
        <label className="block">For asset type (empty = any)<input maxLength={255} className={inputClass} value={assetType} onChange={e => setAssetType(e.target.value)} /></label>
      </div>
      {items.map((item, index) => <div key={index} className="flex flex-wrap items-center gap-2">
        <input required placeholder="Check (e.g. Brakes work)" maxLength={255} className="min-w-48 flex-1 rounded border p-1.5" value={item.label} onChange={e => setItem(index, { label: e.target.value, key: '' })} />
        <select className="rounded border p-1.5" value={item.type} onChange={e => setItem(index, { type: e.target.value as ChecklistItem['type'] })}>
          <option value="pass_fail">Pass / fail</option><option value="number">Number</option><option value="text">Text</option>
        </select>
        <label className="flex items-center gap-1"><input type="checkbox" checked={!!item.required} onChange={e => setItem(index, { required: e.target.checked })} /> Required</label>
        {items.length > 1 && <button type="button" className="text-xs text-red-700" onClick={() => setItems(list => list.filter((_, i) => i !== index))}>Remove</button>}
      </div>)}
      <div className="flex gap-2">
        <button type="button" className={secondaryButtonClass} onClick={() => setItems(list => [...list, blankItem()])}>Add check</button>
        <button className={buttonClass}>Create checklist</button>
      </div>
    </form></Can>
  </Section>
}
