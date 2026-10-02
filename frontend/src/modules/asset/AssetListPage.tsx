import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import type { ApiClient, AssetQuery, CustomField } from '../../core/api/client'
import { ErrorMessage, StatusBadge, formatDate, useLoad } from '../../pages/shared'
import { AssetForm } from './AssetForm'

const sorts = [['-created_at', 'Newest'], ['created_at', 'Oldest'], ['name', 'Name A–Z'], ['-name', 'Name Z–A'], ['serial_number', 'Serial number'], ['status', 'Status']]

export function AssetListPage({ api }: { api: ApiClient }) {
  const navigate = useNavigate()
  const [query, setQuery] = useState<AssetQuery>({ page: 1, per_page: 25, sort: '-created_at' })
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)
  const [customFields, setCustomFields] = useState<CustomField[]>([])

  const load = useCallback(() => api.assets(query), [api, query])
  const { value, error, loading } = useLoad(load)

  // Custom field definitions are optional for the form; a role without
  // access simply gets the core fields.
  useEffect(() => { api.customFields().then(setCustomFields).catch(() => setCustomFields([])) }, [api])

  const update = (patch: AssetQuery) => setQuery(current => ({ ...current, page: 1, ...patch }))
  const submitSearch = (event: FormEvent) => { event.preventDefault(); update({ search: search.trim() || undefined }) }
  const meta = value?.meta

  return <>
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div><h2 className="text-2xl font-bold">Assets</h2><p className="mt-1 text-slate-600">{meta ? `${meta.total} asset(s) in this project` : 'Assets in this project'}</p></div>
      <button className="rounded bg-blue-600 px-4 py-2 text-sm text-white" onClick={() => setCreating(true)}>New asset</button>
    </div>

    {creating && <div className="mt-5 rounded-lg border bg-white p-5">
      <h3 className="mb-4 font-semibold">New asset</h3>
      <AssetForm customFields={customFields} onCancel={() => setCreating(false)}
        onSubmit={async input => { const asset = await api.createAsset(input); navigate(`/assets/${asset.system_id}`) }} />
    </div>}

    <div className="mt-5 flex flex-wrap gap-2">
      <form onSubmit={submitSearch} className="flex flex-1 gap-2">
        <input className="w-full min-w-48 rounded border p-2 text-sm" placeholder="Search name, serial number or system ID" value={search} onChange={e => setSearch(e.target.value)} />
        <button className="rounded border bg-white px-4 text-sm">Search</button>
      </form>
      <input className="w-40 rounded border p-2 text-sm" placeholder="Status filter" value={query.status ?? ''} onChange={e => update({ status: e.target.value || undefined })} />
      <input className="w-40 rounded border p-2 text-sm" placeholder="Type filter" value={query.asset_type ?? ''} onChange={e => update({ asset_type: e.target.value || undefined })} />
      <select className="rounded border p-2 text-sm" value={query.sort} onChange={e => update({ sort: e.target.value })}>{sorts.map(([key, text]) => <option key={key} value={key}>{text}</option>)}</select>
    </div>

    <ErrorMessage error={error} />
    <div className="mt-4 overflow-x-auto rounded-lg border bg-white">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">System ID</th><th className="p-3">Name</th><th className="p-3">Serial</th><th className="p-3">Type</th><th className="p-3">Location</th><th className="p-3">Status</th><th className="p-3">Created</th></tr></thead>
        <tbody>
          {value?.data.map(asset => <tr key={asset.system_id} className="border-t hover:bg-slate-50">
            <td className="p-3 font-mono text-xs"><Link className="text-blue-700 hover:underline" to={`/assets/${asset.system_id}`}>{asset.system_id}</Link></td>
            <td className="p-3">{asset.name}</td>
            <td className="p-3 font-mono text-xs">{asset.serial_number}</td>
            <td className="p-3">{asset.asset_type ?? '—'}</td>
            <td className="p-3">{asset.current_location?.name ?? '—'}</td>
            <td className="p-3"><StatusBadge status={asset.status} /></td>
            <td className="p-3 text-xs text-slate-500">{formatDate(asset.created_at)}</td>
          </tr>)}
          {value && !value.data.length && <tr><td colSpan={7} className="p-6 text-center text-slate-500">{loading ? 'Loading…' : 'No assets match.'}</td></tr>}
        </tbody>
      </table>
    </div>

    {meta && meta.last_page > 1 && <div className="mt-4 flex items-center justify-end gap-3 text-sm">
      <button className="rounded border bg-white px-3 py-1 disabled:opacity-50" disabled={meta.current_page <= 1} onClick={() => setQuery(q => ({ ...q, page: meta.current_page - 1 }))}>Previous</button>
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <button className="rounded border bg-white px-3 py-1 disabled:opacity-50" disabled={meta.current_page >= meta.last_page} onClick={() => setQuery(q => ({ ...q, page: meta.current_page + 1 }))}>Next</button>
    </div>}
  </>
}
