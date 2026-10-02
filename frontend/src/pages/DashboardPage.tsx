import { useCallback } from 'react'
import { Link } from 'react-router-dom'
import type { ApiClient } from '../core/api/client'
import { Card, ErrorMessage, Section, StatusBadge, formatDate, label, useLoad } from './shared'

function Distribution({ counts, total }: { counts: Record<string, number>; total: number }) {
  const entries = Object.entries(counts).sort((a, b) => b[1] - a[1])
  if (!entries.length) return <p className="text-sm text-slate-500">No assets in this project yet.</p>
  return <div className="space-y-4">{entries.map(([name, count]) => <div key={name}>
    <div className="mb-1 flex justify-between text-sm"><span>{label(name)}</span><span>{count}</span></div>
    <div className="h-2 rounded-full bg-slate-100"><div className="h-2 rounded-full bg-blue-600" style={{ width: `${total ? Math.round((count / total) * 100) : 0}%` }} /></div>
  </div>)}</div>
}

export function DashboardPage({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.dashboard(), [api])
  const { value, error } = useLoad(load)
  const totals = value?.totals

  return <>
    <div className="flex items-end justify-between gap-4"><div><h2 className="text-2xl font-bold">Dashboard</h2><p className="mt-1 text-slate-600">Live overview of the selected project.</p></div></div>
    <ErrorMessage error={error} />
    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Card label="Total assets" value={totals?.assets ?? '—'} />
      <Card label="Active assets" value={totals?.active ?? '—'} />
      <Card label="Tracked by devices" value={totals?.tracked ?? '—'} />
      <Card label="Offline assets" value={totals?.offline ?? '—'} hint={value ? `Tracked, not seen for ${value.offline_after_minutes} min` : undefined} />
    </div>
    <div className="mt-8 grid gap-6 lg:grid-cols-2">
      <Section title="Asset distribution by status"><Distribution counts={value?.by_status ?? {}} total={totals?.assets ?? 0} /></Section>
      <Section title="Recent activity">
        <ul className="divide-y">
          {value?.recent_activity.map(entry => <li key={entry.id} className="flex items-center justify-between gap-3 py-3 text-sm">
            <div>
              <p className="font-medium">{label(entry.action)} · {entry.asset_system_id
                ? <Link className="text-blue-700 hover:underline" to={`/assets/${entry.asset_system_id}`}>{entry.asset_name ?? entry.asset_system_id}</Link>
                : 'asset'}</p>
              <p className="text-xs text-slate-500">{entry.user ?? 'System'} · {formatDate(entry.occurred_at)}</p>
            </div>
          </li>)}
          {value && !value.recent_activity.length && <li className="py-3 text-sm text-slate-500">No recent asset activity.</li>}
        </ul>
      </Section>
      <Section title="Assets by location">
        <ul className="divide-y text-sm">{value?.by_location.map(row => <li key={row.location_id} className="flex justify-between py-2"><span>{row.name}</span><span>{row.count}</span></li>)}
          {value && !value.by_location.length && <li className="py-2 text-slate-500">No asset has a location yet.</li>}</ul>
      </Section>
      <Section title="Integrations">
        <div className="flex flex-wrap gap-3 text-sm">{Object.entries(value?.integrations ?? {}).map(([status, count]) => <span key={status} className="flex items-center gap-2"><StatusBadge status={status} />{count}</span>)}
          {value && !Object.keys(value.integrations).length && <span className="text-slate-500">No integrations configured.</span>}</div>
      </Section>
    </div>
  </>
}
