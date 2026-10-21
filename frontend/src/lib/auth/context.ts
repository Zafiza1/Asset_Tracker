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
  /** Make another organization of this account the active one. */
  switchOrganization: (organizationId: string) => Promise<Profile>
  /** Create another organization with the logged-in account as its administrator, and switch to it. */
  createOrganization: (data: NewOrganizationData) => Promise<Profile>
  logout: () => Promise<void>
  setProfile: (profile: Profile) => void
}

export interface NewOrganizationData {
  organization_name: string
  organization_code: string
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

/**
 * Where a freshly signed-in user goes: change a temporary password first, then, for an
 * account in several organizations, choose which one to work in before the dashboard.
 */
export function landingPath(profile: Profile, from?: string): string {
  if (profile.user.must_change_password) return '/ubah-password'
  if (profile.user.user_type === 'platform') return from ?? '/platform/organisasi'
  if (profile.organizations.length > 1) return '/pilih-organisasi'
  return from ?? '/'
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
