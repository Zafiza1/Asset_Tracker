import { useCallback } from 'react'
import type { ApiClient } from '../core/api/client'
import { ErrorMessage, useLoad } from './shared'
export function LocationsPage({ api }: { api: ApiClient }) { const load = useCallback(() => api.locations(), [api]); const { value, error } = useLoad(load); return <><h2 className="text-2xl font-bold">Locations</h2><ErrorMessage error={error} /><div className="mt-5 grid gap-3 sm:grid-cols-2">{value?.data.map(location => <article key={location.id} className="rounded-lg border bg-white p-4"><h3 className="font-semibold">{location.name}</h3><p className="mt-1 text-sm text-slate-500">{location.type ?? 'Unclassified'}{location.address ? ` · ${location.address}` : ''}</p></article>)}</div></> }
