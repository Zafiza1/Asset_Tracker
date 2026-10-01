import { useCallback, useMemo } from 'react'
import type { ApiClient } from '../core/api/client'
import { Card, ErrorMessage, useLoad } from './shared'

const label = (status: string) => status.replace(/[-_]/g, ' ').replace(/\b\w/g, character => character.toUpperCase())

export function DashboardPage({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.assets(), [api])
  const { value, error } = useLoad(load)
  const assets = useMemo(() => value?.data ?? [], [value])
  const distribution = useMemo(() => assets.reduce<Record<string, number>>((counts, asset) => {
    counts[asset.status] = (counts[asset.status] ?? 0) + 1
    return counts
  }, {}), [assets])
  const total = value?.meta?.total ?? assets.length
  const active = assets.filter(asset => asset.status === 'active').length
  const offline = assets.filter(asset => asset.status === 'offline').length

  return <>
    <div className="flex items-end justify-between gap-4"><div><h2 className="text-2xl font-bold">Dashboard</h2><p className="mt-1 text-slate-600">Live overview of the selected project.</p></div><span className="text-sm text-slate-500">Tenant-scoped data</span></div>
    <ErrorMessage error={error} />
    <div className="mt-6 grid gap-4 sm:grid-cols-3"><Card label="Total assets" value={total} /><Card label="Active assets" value={active} /><Card label="Offline assets" value={offline} /></div>
    <div className="mt-8 grid gap-6 lg:grid-cols-2">
      <section className="rounded-lg border bg-white p-5"><h3 className="font-semibold">Asset distribution</h3><div className="mt-5 space-y-4">{Object.entries(distribution).map(([status, count]) => <div key={status}><div className="mb-1 flex justify-between text-sm"><span>{label(status)}</span><span>{count}</span></div><div className="h-2 rounded-full bg-slate-100"><div className="h-2 rounded-full bg-blue-600" style={{ width: `${total ? Math.round((count / total) * 100) : 0}%` }} /></div></div>)}{!assets.length && !error && <p className="text-sm text-slate-500">No assets in this project yet.</p>}</div></section>
      <section className="rounded-lg border bg-white p-5"><h3 className="font-semibold">Recent activity</h3><ul className="mt-3 divide-y">{assets.slice(0, 5).map(asset => <li key={asset.system_id} className="flex items-center justify-between gap-3 py-3 text-sm"><div><p className="font-medium">{asset.name}</p><p className="font-mono text-xs text-slate-500">{asset.system_id}</p></div><span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{label(asset.status)}</span></li>)}{!assets.length && !error && <li className="py-3 text-sm text-slate-500">No recent asset activity.</li>}</ul></section>
    </div>
  </>
}
