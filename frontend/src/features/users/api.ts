import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api/client'
import type { Role } from '../../lib/api/types'

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api.get<{ data: Role[] }>('/roles')).data,
    enabled,
  })
}
