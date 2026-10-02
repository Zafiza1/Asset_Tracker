/* eslint-disable react-refresh/only-export-components */
import { useCallback, useEffect, useState, type ReactNode } from 'react'

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

const statusColors: Record<string, string> = { active: 'bg-green-100 text-green-800', online: 'bg-green-100 text-green-800', connected: 'bg-green-100 text-green-800', degraded: 'bg-amber-100 text-amber-800', maintenance: 'bg-amber-100 text-amber-800', offline: 'bg-slate-200 text-slate-700', disconnected: 'bg-slate-200 text-slate-700' }
export function StatusBadge({ status }: { status: string }) {
  return <span className={`rounded-full px-2 py-0.5 text-xs ${statusColors[status] ?? 'bg-slate-100 text-slate-700'}`}>{label(status)}</span>
}

export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
  return <section className="rounded-lg border bg-white p-5"><div className="flex items-center justify-between gap-3"><h3 className="font-semibold">{title}</h3>{actions}</div><div className="mt-3">{children}</div></section>
}
