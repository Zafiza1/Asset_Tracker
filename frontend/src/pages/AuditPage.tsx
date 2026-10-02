import { useCallback, useState, type ReactNode } from 'react'
import type { ApiClient, ApiList } from '../core/api/client'
import { Card, ErrorMessage, PageHeader, StatusBadge, formatDate, label, useLoad } from './shared'

type Tab = 'activity' | 'events' | 'security'

/** Activity (who changed what), event (what devices/systems reported) and security logs. */
export function AuditPage({ api }: { api: ApiClient }) {
  const [tab, setTab] = useState<Tab>('activity')
  const statsLoad = useCallback(() => api.auditStats(), [api])
  const stats = useLoad(statsLoad)
  const s = stats.value

  return <>
    <PageHeader title="Audit log" description="Last 30 days. Secrets and passwords are redacted before anything is stored." />
    <div className="mt-5 grid gap-4 sm:grid-cols-4">
      <Card label="Activity entries" value={s?.activity_logs.total ?? '—'} />
      <Card label="Events processed" value={s?.event_logs.processed ?? '—'} hint={s ? `${s.event_logs.failed} failed · ${s.event_logs.pending} pending` : undefined} />
      <Card label="Security events" value={s?.security_logs.total ?? '—'} />
      <Card label="Suspicious" value={s?.security_logs.suspicious ?? '—'} />
    </div>
    <div className="mt-6 flex gap-2">{(['activity', 'events', 'security'] as Tab[]).map(t => <button key={t} onClick={() => setTab(t)}
      className={`rounded px-3 py-1.5 text-sm ${tab === t ? 'bg-blue-600 text-white' : 'border bg-white'}`}>{label(t)}</button>)}</div>
    <ErrorMessage error={stats.error} />
    {tab === 'activity' && <ActivityTable api={api} />}
    {tab === 'events' && <EventTable api={api} />}
    {tab === 'security' && <SecurityTable api={api} />}
  </>
}

function usePagedLogs<T>(fetchPage: (page: number) => Promise<ApiList<T>>) {
  const [page, setPage] = useState(1)
  const load = useCallback(() => fetchPage(page), [fetchPage, page])
  const { value, error } = useLoad(load)
  const pager = value?.meta && value.meta.last_page > 1 && <div className="mt-4 flex items-center justify-end gap-3 text-sm">
    <button className="rounded border bg-white px-3 py-1 disabled:opacity-50" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
    <span>Page {value.meta.current_page} of {value.meta.last_page}</span>
    <button className="rounded border bg-white px-3 py-1 disabled:opacity-50" disabled={page >= value.meta.last_page} onClick={() => setPage(p => p + 1)}>Next</button>
  </div>
  return { rows: value?.data, error, pager }
}

function LogTable({ head, error, empty, pager, children }: { head: string[]; error: string; empty: boolean; pager: ReactNode; children: ReactNode }) {
  return <>
    <ErrorMessage error={error} />
    <div className="mt-4 overflow-x-auto rounded-lg border bg-white">
      <table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr>{head.map(h => <th key={h} className="p-3">{h}</th>)}</tr></thead><tbody>{children}</tbody></table>
      {empty && <p className="p-6 text-center text-sm text-slate-500">No entries.</p>}
    </div>
    {pager}
  </>
}

function ActivityTable({ api }: { api: ApiClient }) {
  const { rows, error, pager } = usePagedLogs(useCallback((page: number) => api.activityLogs(page), [api]))
  return <LogTable head={['When', 'User', 'Action', 'Resource', 'IP']} error={error} empty={rows?.length === 0} pager={pager}>
    {rows?.map(row => <tr key={row.id} className="border-t">
      <td className="p-3 text-xs text-slate-500">{formatDate(row.occurred_at)}</td><td className="p-3">{row.user?.name ?? 'System'}</td>
      <td className="p-3 font-mono text-xs">{row.action}</td><td className="p-3 text-xs">{row.resource_type ? `${row.resource_type.split('\\').pop()} #${row.resource_id}` : '—'}</td><td className="p-3 text-xs">{row.ip_address ?? '—'}</td>
    </tr>)}
  </LogTable>
}

function EventTable({ api }: { api: ApiClient }) {
  const { rows, error, pager } = usePagedLogs(useCallback((page: number) => api.eventLogs(page), [api]))
  return <LogTable head={['When', 'Event', 'Source', 'Asset', 'Device', 'Status']} error={error} empty={rows?.length === 0} pager={pager}>
    {rows?.map(row => <tr key={row.id} className="border-t">
      <td className="p-3 text-xs text-slate-500">{formatDate(row.occurred_at)}</td><td className="p-3 font-mono text-xs">{row.event_type}</td><td className="p-3">{row.source ?? '—'}</td>
      <td className="p-3 font-mono text-xs">{row.asset?.system_id ?? '—'}</td><td className="p-3 font-mono text-xs">{row.device?.serial_number ?? row.device?.system_id ?? '—'}</td>
      <td className="p-3"><StatusBadge status={row.status} />{row.error_message && <p className="text-xs text-red-600">{row.error_message}</p>}</td>
    </tr>)}
  </LogTable>
}

function SecurityTable({ api }: { api: ApiClient }) {
  const { rows, error, pager } = usePagedLogs(useCallback((page: number) => api.securityLogs(page), [api]))
  return <LogTable head={['When', 'Event', 'User', 'Severity', 'IP']} error={error} empty={rows?.length === 0} pager={pager}>
    {rows?.map(row => <tr key={row.id} className={`border-t ${row.is_suspicious ? 'bg-red-50' : ''}`}>
      <td className="p-3 text-xs text-slate-500">{formatDate(row.occurred_at)}</td><td className="p-3 font-mono text-xs">{row.event_type}</td><td className="p-3">{row.user?.email ?? '—'}</td>
      <td className="p-3"><StatusBadge status={row.severity} /></td><td className="p-3 text-xs">{row.ip_address ?? '—'}</td>
    </tr>)}
  </LogTable>
}
