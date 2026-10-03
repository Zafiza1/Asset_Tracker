/* eslint-disable react-refresh/only-export-components */
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { ApiError } from '../core/api/client'

export function useLoad<T>(loader: () => Promise<T>) {
  const [value, setValue] = useState<T | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [version, setVersion] = useState(0)
  useEffect(() => {
    let active = true
    setLoading(true)
    loader()
      .then(result => { if (active) { setValue(result); setError('') } })
      .catch(e => { if (active) setError(e instanceof Error ? e.message : 'Unable to load data') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [loader, version])
  const reload = useCallback(() => setVersion(v => v + 1), [])
  return { value, error, loading, reload }
}

export const label = (value: string) => value.replace(/[-_.]/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
export const formatDate = (value?: string | null) => value ? new Date(value).toLocaleString() : '—'

export function ErrorMessage({ error }: { error: string }) { return error ? <p className="mt-3 rounded bg-red-50 p-3 text-sm text-red-700">{error}</p> : null }

export function Card({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return <div className="rounded-lg border bg-white p-5"><p className="text-sm text-slate-500">{label}</p><p className="mt-1 text-2xl font-semibold">{value}</p>{hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}</div>
}

const good = 'bg-green-100 text-green-800', warn = 'bg-amber-100 text-amber-800', bad = 'bg-red-100 text-red-800', idle = 'bg-slate-200 text-slate-700'
const statusColors: Record<string, string> = {
  active: good, online: good, connected: good, enabled: good, healthy: good, delivered: good, processed: good, available: good, completed: good, pass: good, found: good, open: warn,
  degraded: warn, maintenance: warn, installed: warn, configured: warn, pending: warn, retrying: warn, warning: warn, deprecated: warn, scheduled: warn, in_progress: warn, in_transit: warn, reserved: warn,
  error: bad, failed: bad, unhealthy: bad, critical: bad, revoked: bad, suspended: bad, overdue: bad, fail: bad, missing: bad, low: bad, unexpected: warn,
  offline: idle, disconnected: idle, disabled: idle, inactive: idle, unavailable: idle, cancelled: idle, returned: idle,
}
export function StatusBadge({ status }: { status: string }) {
  return <span className={`rounded-full px-2 py-0.5 text-xs ${statusColors[status] ?? 'bg-slate-100 text-slate-700'}`}>{label(status)}</span>
}

export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
  return <section className="rounded-lg border bg-white p-5"><div className="flex items-center justify-between gap-3"><h3 className="font-semibold">{title}</h3>{actions}</div><div className="mt-3">{children}</div></section>
}

/** First field-level validation message when there is one, else the error message. */
export const errorText = (error: unknown, fallback = 'Request failed') => {
  if (error instanceof ApiError) return Object.values(error.errors ?? {}).flat()[0] ?? error.message
  return error instanceof Error ? error.message : fallback
}

export const inputClass = 'mt-1 w-full rounded border p-2 text-sm'
export const buttonClass = 'rounded bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-60'
export const secondaryButtonClass = 'rounded border bg-white px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-60'

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return <div className="flex flex-wrap items-end justify-between gap-4"><div><h2 className="text-2xl font-bold">{title}</h2>{description && <p className="mt-1 text-slate-600">{description}</p>}</div>{actions}</div>
}

export function Notice({ message, tone = 'info' }: { message: ReactNode; tone?: 'info' | 'success' | 'warning' }) {
  if (!message) return null
  const tones = { info: 'bg-blue-50 text-blue-800', success: 'bg-green-50 text-green-800', warning: 'bg-amber-50 text-amber-800' }
  return <p className={`mt-3 rounded p-3 text-sm ${tones[tone]}`}>{message}</p>
}

export function Empty({ children }: { children: ReactNode }) { return <p className="rounded-lg border bg-white p-6 text-sm text-slate-600">{children}</p> }
