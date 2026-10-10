import { hashKey, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { api, ApiError, onUnauthorized } from '../api/client'
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

  useEffect(
    () =>
      onUnauthorized(() => {
        // Keep the session query itself: clearing it while /auth/me is still in flight (the
        // 401 on first load) strands its observer on a removed query and the app never leaves
        // the loading state.
        queryClient.removeQueries({ predicate: (q) => q.queryHash !== hashKey(ME_KEY) })
        setProfile(null)
      }),
    [queryClient, setProfile],
  )

  const value = useMemo<AuthState>(() => {
    const permissions = new Set(data?.permissions ?? [])
    return {
      profile: data ?? null,
      isLoading,
      can: (permission) => permissions.has(permission),
      setProfile,
      login: async (email, password) => {
        const profile = (await api.post<Resource<Profile>>('/auth/login', { email, password })).data
        queryClient.clear()
        setProfile(profile)
        return profile
      },
      logout: async () => {
        try {
          await api.post('/auth/logout')
        } finally {
          queryClient.clear()
          setProfile(null)
        }
      },
    }
  }, [data, isLoading, queryClient, setProfile])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
