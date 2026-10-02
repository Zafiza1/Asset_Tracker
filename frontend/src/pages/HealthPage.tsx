import { useCallback } from 'react'
import type { ApiClient } from '../core/api/client'
import { ErrorMessage, PageHeader, Section, StatusBadge, formatDate, label, secondaryButtonClass, useLoad } from './shared'

/** Platform health (database, Redis, queue) and this project's integration health. */
export function HealthPage({ api }: { api: ApiClient }) {
  const load = useCallback(async () => {
    const [platform, integrations] = await Promise.all([api.platformHealth().catch(() => null), api.integrationsHealth()])
    return { platform, integrations }
  }, [api])
  const { value, error, reload } = useLoad(load)
  const platform = value?.platform

  return <>
    <PageHeader title="Health" description="An integration failure degrades only that integration; the platform keeps running." actions={<button className={secondaryButtonClass} onClick={reload}>Refresh</button>} />
    <ErrorMessage error={error} />
    <div className="mt-5 grid gap-6 lg:grid-cols-2">
      <Section title="Platform" actions={platform && <StatusBadge status={platform.status} />}>
        {platform ? <ul className="divide-y text-sm">{Object.entries(platform.checks).map(([name, check]) => <li key={name} className="flex items-center justify-between py-2">
          <span>{label(name)}</span><span className="flex items-center gap-2">{check.error && <span className="text-xs text-red-600">{check.error}</span>}<StatusBadge status={check.status} /></span>
        </li>)}</ul> : <p className="text-sm text-slate-500">Health endpoint unreachable.</p>}
        {platform && <p className="mt-2 text-xs text-slate-400">Checked {formatDate(platform.timestamp)}</p>}
      </Section>
      <Section title="Integrations">
        <ul className="divide-y text-sm">{value?.integrations.integrations.map(i => <li key={i.id} className="flex items-center justify-between py-2">
          <span>{i.name} <span className="text-xs text-slate-500">({i.type})</span></span>
          <span className="flex items-center gap-2 text-xs text-slate-500">last check {formatDate(i.last_health_check_at)}<StatusBadge status={i.health} /></span>
        </li>)}
          {value && !value.integrations.integrations.length && <li className="py-2 text-slate-500">No integrations configured.</li>}</ul>
      </Section>
    </div>
  </>
}
