import { hashKey, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { api, ApiError, onOrganizationChanged, onUnauthorized, setActiveOrganization } from '../api/client'
import type { Profile, Resource } from '../api/types'
import { AuthContext, ME_KEY, type AuthState } from './context'

async function fetchMe(): Promise<Profile | null> {
  try {
    return (await api.get<Resource<Profile>>('/auth/me')).data
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return null
    throw e
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ME_KEY, queryFn: fetchMe, staleTime: 60_000, retry: false })

  const setProfile = useCallback((p: Profile | null) => queryClient.setQueryData(ME_KEY, p), [queryClient])

  /**
   * Replace the session profile and drop every other cached query (data of the previous
   * user or organization). The profile query itself is kept: removing it strands the
   * observer above on a removed query (e.g. while /auth/me is in flight on first load),
   * and the new profile would never render.
   */
  const resetSession = useCallback(
    <P extends Profile | null>(p: P): P => {
      queryClient.removeQueries({ predicate: (q) => q.queryHash !== hashKey(ME_KEY) })
      setProfile(p)
      return p
    },
    [queryClient, setProfile],
  )

  // Every API call states which organization the UI shows; the server rejects stale ones.
  setActiveOrganization(data?.organization?.id ?? null)

  useEffect(() => onUnauthorized(() => resetSession(null)), [resetSession])

  useEffect(
    () =>
      onOrganizationChanged(() => {
        // The active organization changed elsewhere or was revoked: drop its cached data
        // and reload the profile (which carries the new active organization, if any).
        queryClient.removeQueries({ predicate: (q) => q.queryHash !== hashKey(ME_KEY) })
        void queryClient.invalidateQueries({ queryKey: ME_KEY })
      }),
    [queryClient],
  )

  const value = useMemo<AuthState>(() => {
    const permissions = new Set(data?.permissions ?? [])
    return {
      profile: data ?? null,
      isLoading,
      can: (permission) => permissions.has(permission),
      setProfile,
      login: async (email, password) => resetSession((await api.post<Resource<Profile>>('/auth/login', { email, password })).data),
      signUp: async (data) => resetSession((await api.post<Resource<Profile>>('/auth/register', data)).data),
      switchOrganization: async (organizationId) =>
        resetSession((await api.put<Resource<Profile>>('/auth/organization', { organization_id: organizationId })).data),
      createOrganization: async (data) => resetSession((await api.post<Resource<Profile>>('/auth/organizations', data)).data),
      logout: async () => {
        try {
          await api.post('/auth/logout')
        } finally {
          resetSession(null)
        }
      },
    }
  }, [data, isLoading, resetSession, setProfile])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
