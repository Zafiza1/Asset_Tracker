/* eslint-disable react-refresh/only-export-components */
import { useEffect, useState } from 'react'
export function useLoad<T>(loader: () => Promise<T>) { const [value, setValue] = useState<T | null>(null); const [error, setError] = useState(''); useEffect(() => { loader().then(setValue).catch(e => setError(e instanceof Error ? e.message : 'Unable to load data')) }, [loader]); return { value, error } }
export function ErrorMessage({ error }: { error: string }) { return error ? <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p> : null }
export function Card({ label, value }: { label: string; value: string | number }) { return <div className="rounded-lg border bg-white p-5"><p className="text-sm text-slate-500">{label}</p><p className="mt-1 text-2xl font-semibold">{value}</p></div> }
