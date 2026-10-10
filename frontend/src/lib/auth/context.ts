import { createContext, useContext } from 'react'
import type { Profile } from '../api/types'

export interface AuthState {
  profile: Profile | null
  isLoading: boolean
  /** UI convenience only — the backend enforces every permission independently. */
  can: (permission: string) => boolean
  login: (email: string, password: string) => Promise<Profile>
  logout: () => Promise<void>
  setProfile: (profile: Profile) => void
}

export const AuthContext = createContext<AuthState | null>(null)

export const ME_KEY = ['auth', 'me'] as const

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
