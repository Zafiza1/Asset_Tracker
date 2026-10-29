import { useLayoutEffect, useMemo } from 'react'
import { useSearchParams } from 'react-router'

type FilterState = Record<string, string>

const storageKeyOf = (endpoint: string) => `filters:${endpoint}`

function readStored(endpoint: string): FilterState {
  if (typeof window === 'undefined') return {}
  try {
    const saved = localStorage.getItem(storageKeyOf(endpoint))
    return saved ? JSON.parse(saved) : {}
  } catch {
    return {}
  }
}

function writeStored(endpoint: string, filters: FilterState) {
  try {
    localStorage.setItem(storageKeyOf(endpoint), JSON.stringify(filters))
  } catch {
    // Storage unavailable (private mode, quota) — filters just won't persist.
  }
}

/**
 * Persist filter state in localStorage keyed by endpoint.
 * Writes are synchronous so a reset is visible to the very next render.
 */
export function usePersistedFilters(endpoint: string) {
  const updateFilter = (key: string, value: string) => {
    writeStored(endpoint, { ...readStored(endpoint), [key]: value })
  }

  const removeFilter = (key: string) => {
    const next = readStored(endpoint)
    delete next[key]
    writeStored(endpoint, next)
  }

  const resetFilters = () => writeStored(endpoint, {})

  return { updateFilter, removeFilter, resetFilters }
}

/**
 * Get initial search params from localStorage if URL params are empty.
 */
export function getInitialParamsFromStorage(endpoint: string, currentParams: URLSearchParams): string {
  if (currentParams.toString().length > 0) return currentParams.toString()

  const merged = new URLSearchParams(currentParams)
  Object.entries(readStored(endpoint)).forEach(([k, v]) => {
    if (v && typeof v === 'string') merged.set(k, v)
  })
  return merged.toString()
}

/**
 * useSearchParams that falls back to the filters saved for this endpoint when the URL has none.
 * The restored params are returned on the first render, so the page never fetches
 * (or flashes) the unfiltered list before the saved filters kick in; the URL is then
 * synced with a replace so Back doesn't bounce through the empty URL.
 */
export function usePersistedSearchParams(endpoint: string) {
  const [params, setParams] = useSearchParams()
  const restored = useMemo(
    () => (params.toString() ? params : new URLSearchParams(getInitialParamsFromStorage(endpoint, params))),
    [endpoint, params],
  )

  useLayoutEffect(() => {
    if (restored !== params && restored.toString()) setParams(restored, { replace: true })
  }, [restored, params, setParams])

  return [restored, setParams] as const
}
