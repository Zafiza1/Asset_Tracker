import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api/client'
import type { Role, UserOrganizationOption } from '../../lib/api/types'

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api.get<{ data: Role[] }>('/roles')).data,
    enabled,
  })
}

/** Organizations where the current administrator may add users (current organization first). */
export function useOrganizationOptions(enabled = true) {
  return useQuery({
    queryKey: ['users', 'organization-options'],
    queryFn: async () => (await api.get<{ data: UserOrganizationOption[] }>('/users/organization-options')).data,
    enabled,
  })
}
