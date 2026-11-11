import Constants from 'expo-constants'
import * as SecureStore from 'expo-secure-store'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Platform } from 'react-native'
import { ApiError, normalizeServer, request } from './api'
import type { Organization, Profile } from './types'

/**
 * The logged-in account: server address, bearer token (bound to one organization
 * by the backend) and profile, kept in the device's secure storage between launches.
 */
interface Session {
  ready: boolean
  server: string
  token: string | null
  profile: Profile | null
  login: (input: { server: string; email: string; password: string; organizationId?: string }) => Promise<void>
  logout: () => Promise<void>
  /** GET {server}/api{path} with the token; a rejected token ends the session. */
  get: <T>(path: string) => Promise<T>
  /** POST {server}/api{path} with a JSON body; same session handling as get. */
  post: <T>(path: string, body?: unknown) => Promise<T>
  can: (permission: string) => boolean
  /** Optional module (e.g. 'barcode') switched on for the organization. */
  hasModule: (module: string) => boolean
}

const KEYS = { server: 'server', token: 'token', profile: 'profile' } as const

const SessionContext = createContext<Session | null>(null)

/** The backend asks to choose when the account belongs to several organizations. */
export class OrganizationChoiceRequired extends Error {
  constructor(public readonly organizations: Organization[]) {
    super('Pilih organisasi terlebih dahulu.')
  }
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false)
  const [server, setServer] = useState('')
  const [token, setToken] = useState<string | null>(null)
  const [profile, setProfile] = useState<Profile | null>(null)

  const clear = useCallback(async () => {
    setToken(null)
    setProfile(null)
    await Promise.all([SecureStore.deleteItemAsync(KEYS.token), SecureStore.deleteItemAsync(KEYS.profile)])
  }, [])

  useEffect(() => {
    ;(async () => {
      let saved: (string | null)[] = []
      let savedProfile: Profile | null = null
      try {
        saved = await Promise.all(Object.values(KEYS).map((k) => SecureStore.getItemAsync(k)))
        savedProfile = saved[2] ? JSON.parse(saved[2]) : null
      } catch {
        // Unreadable storage (e.g. keys lost after a reinstall) starts at the login instead of hanging.
        await clear().catch(() => undefined)
        saved = []
      }
      const [savedServer, savedToken] = saved
      setServer(savedServer ?? '')
      if (savedServer && savedToken && savedProfile) {
        setToken(savedToken)
        setProfile(savedProfile)
        setReady(true)
        // Refresh permissions; offline keeps the saved profile, a rejected token logs out.
        request<{ data: Profile }>(savedServer, '/auth/mobile/me', { token: savedToken })
          .then((r) => {
            setProfile(r.data)
            return SecureStore.setItemAsync(KEYS.profile, JSON.stringify(r.data))
          })
          .catch(async (e) => {
            if (e instanceof ApiError && e.status === 401) await clear()
          })
        return
      }
      setReady(true)
    })()
  }, [clear])

  const login: Session['login'] = useCallback(async ({ server: input, email, password, organizationId }) => {
    const base = normalizeServer(input)
    try {
      const r = await request<{ data: Profile & { token: string } }>(base, '/auth/mobile/login', {
        method: 'POST',
        body: {
          email: email.trim(),
          password,
          device_name: (Constants.deviceName ?? `${Platform.OS} device`).slice(0, 100),
          organization_id: organizationId ?? null,
        },
      })
      const { token: newToken, ...newProfile } = r.data
      await Promise.all([
        SecureStore.setItemAsync(KEYS.server, base),
        SecureStore.setItemAsync(KEYS.token, newToken),
        SecureStore.setItemAsync(KEYS.profile, JSON.stringify(newProfile)),
      ])
      setServer(base)
      setToken(newToken)
      setProfile(newProfile)
    } catch (e) {
      if (e instanceof ApiError && e.code === 'ORGANIZATION_SELECTION_REQUIRED') {
        throw new OrganizationChoiceRequired((e.details.organizations as Organization[]) ?? [])
      }
      throw e
    }
  }, [])

  const logout = useCallback(async () => {
    // Revoke on the server when reachable; the device forgets the token either way.
    await request(server, '/auth/mobile/logout', { method: 'POST', token }).catch(() => undefined)
    await clear()
  }, [server, token, clear])

  const call = useCallback(
    async <T,>(path: string, options: { method?: 'GET' | 'POST'; body?: unknown } = {}) => {
      try {
        return await request<T>(server, path, { ...options, token })
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) await clear()
        throw e
      }
    },
    [server, token, clear],
  )
  const get = useCallback(<T,>(path: string) => call<T>(path), [call])
  const post = useCallback(<T,>(path: string, body: unknown = {}) => call<T>(path, { method: 'POST', body }), [call])

  const value = useMemo<Session>(
    () => ({
      ready,
      server,
      token,
      profile,
      login,
      logout,
      get,
      post,
      can: (p) => profile?.permissions.includes(p) ?? false,
      hasModule: (m) => profile?.modules?.includes(m) ?? false,
    }),
    [ready, server, token, profile, login, logout, get, post],
  )

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): Session {
  const session = useContext(SessionContext)
  if (!session) throw new Error('useSession outside SessionProvider')
  return session
}
