import { createContext, useContext } from 'react'
import type { Profile } from '../api/types'

export interface AuthState {
  profile: Profile | null
  isLoading: boolean
  /** UI convenience only — the backend enforces every permission independently. */
  can: (permission: string) => boolean
  login: (email: string, password: string) => Promise<Profile>
  /** Sign up a new organization; the registrant becomes its administrator and is logged in. */
  signUp: (data: SignUpData) => Promise<Profile>
  logout: () => Promise<void>
  setProfile: (profile: Profile) => void
}

export interface SignUpData {
  organization_name: string
  organization_code: string
  name: string
  email: string
  password: string
  password_confirmation: string
}

export const AuthContext = createContext<AuthState | null>(null)

export const ME_KEY = ['auth', 'me'] as const

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
