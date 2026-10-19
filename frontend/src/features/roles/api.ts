import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api/client'
import type { Permission } from '../../lib/api/types'

export function usePermissionCatalog(enabled = true) {
  return useQuery({
    queryKey: ['permissions'],
    queryFn: async () => (await api.get<{ data: Permission[] }>('/permissions')).data,
    enabled,
  })
}
