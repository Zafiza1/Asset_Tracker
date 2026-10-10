import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { RequirePermission } from '../app/guards'
import { Field, Input } from '../components/ui/Field'
import { ApiError, qs } from '../lib/api/client'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'
import { applyApiErrors, passwordRule } from '../lib/forms'

function withAuth(permissions: string[], ui: React.ReactNode) {
  const state: AuthState = {
    profile: { permissions } as unknown as Profile,
    isLoading: false,
    can: (p) => permissions.includes(p),
    login: vi.fn(),
    logout: vi.fn(),
    setProfile: vi.fn(),
  }
  return render(
    <AuthContext.Provider value={state}>
      <MemoryRouter>{ui}</MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('RequirePermission', () => {
  it('renders children when the permission is held', () => {
    withAuth(['user.view'], <RequirePermission permission="user.view">rahasia</RequirePermission>)
    expect(screen.getByText('rahasia')).toBeInTheDocument()
  })

  it('shows access denied otherwise', () => {
    withAuth(['asset.view'], <RequirePermission permission="user.view">rahasia</RequirePermission>)
    expect(screen.queryByText('rahasia')).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Akses ditolak' })).toBeInTheDocument()
  })
})

describe('applyApiErrors', () => {
  it('maps validation errors to fields and returns no banner message', () => {
    const setError = vi.fn()
    const error = new ApiError(422, 'VALIDATION_FAILED', 'Data tidak valid', { fields: { email: ['Email sudah dipakai.'] } })
    expect(applyApiErrors(error, setError)).toBeNull()
    expect(setError).toHaveBeenCalledWith('email', { type: 'server', message: 'Email sudah dipakai.' })
  })

  it('returns the message for business errors', () => {
    const error = new ApiError(409, 'LAST_ORG_ADMIN', 'Minimal satu admin.')
    expect(applyApiErrors(error, vi.fn())).toBe('Minimal satu admin.')
  })
})

describe('Field', () => {
  it('wires label, error and aria attributes', () => {
    render(<Field label="Email" error="Wajib diisi.">{(p) => <Input {...p} />}</Field>)
    const input = screen.getByLabelText('Email')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('Wajib diisi.')
  })
})

describe('helpers', () => {
  it('builds query strings without empty values', () => {
    expect(qs({ page: 2, search: '', status: null, q: 'a b' })).toBe('?page=2&q=a+b')
    expect(qs({})).toBe('')
  })

  it('mirrors the backend password policy', () => {
    expect(passwordRule.test('Rahasia-Uji-123')).toBe(true)
    expect(passwordRule.test('pendek1A')).toBe(false)
    expect(passwordRule.test('semuahurufkecil123')).toBe(false)
  })
})
