import { useQuery } from '@tanstack/react-query'
import { api, qs } from '../../lib/api/client'

interface OptionRow {
  id: string
  code: string
  name: string
  depth?: number
}

export interface SelectOption {
  value: string
  label: string
}

/**
 * Active records of a master endpoint for dropdowns (`all=1`, capped server-side).
 * Selecting is UX only: the API re-validates every reference against the tenant.
 */
export function useOptions<T extends OptionRow = OptionRow>(endpoint: string, params: Record<string, string | undefined> = {}, enabled = true) {
  return useQuery({
    queryKey: ['options', endpoint, params],
    queryFn: async () => (await api.get<{ data: T[] }>(`${endpoint}${qs({ all: 1, status: 'active', ...params })}`)).data,
    enabled,
    staleTime: 30_000,
  })
}

/** Tree-aware labels: children are indented under their parent. */
export function toOptions(rows: OptionRow[] | undefined): SelectOption[] {
  return (rows ?? []).map((r) => ({ value: r.id, label: `${'  '.repeat(r.depth ?? 0)}${r.name} (${r.code})` }))
}
