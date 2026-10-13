import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { RegisterPage } from '../features/auth/RegisterPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

function renderPage(signUp: AuthState['signUp']) {
  const state: AuthState = { profile: null, isLoading: false, can: () => false, login: vi.fn(), signUp, logout: vi.fn(), setProfile: vi.fn() }
  return render(
    <AuthContext.Provider value={state}>
      <MemoryRouter>
        <RegisterPage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

const type = (label: RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('RegisterPage', () => {
  it('suggests the organization code from its name until the code is edited', () => {
    renderPage(vi.fn())
    type(/Nama organisasi/, 'PT Maju Jaya')
    expect(screen.getByLabelText(/Kode organisasi/)).toHaveValue('PTMAJUJAYA')

    type(/Kode organisasi/, 'MAJU')
    type(/Nama organisasi/, 'PT Maju Jaya Abadi')
    expect(screen.getByLabelText(/Kode organisasi/)).toHaveValue('MAJU')
  })

  it('validates the password before submitting', async () => {
    const signUp = vi.fn()
    renderPage(signUp)
    type(/Nama organisasi/, 'PT Maju Jaya')
    type(/Nama lengkap/, 'Rina')
    type(/^Email/, 'rina@maju.test')
    type(/^Password/, 'Rahasia-Kuat-2026')
    type(/Ulangi password/, 'Beda-2026-Sekali')
    fireEvent.click(screen.getByRole('button', { name: 'Daftar' }))

    expect(await screen.findByText('Konfirmasi password tidak sama.')).toBeInTheDocument()
    expect(signUp).not.toHaveBeenCalled()
  })

  it('submits the organization and administrator account', async () => {
    const signUp = vi.fn(async () => ({}) as Profile)
    renderPage(signUp)
    type(/Nama organisasi/, 'PT Maju Jaya')
    type(/Kode organisasi/, 'MAJU')
    type(/Nama lengkap/, 'Rina')
    type(/^Email/, 'rina@maju.test')
    type(/^Password/, 'Rahasia-Kuat-2026')
    type(/Ulangi password/, 'Rahasia-Kuat-2026')
    fireEvent.click(screen.getByRole('button', { name: 'Daftar' }))

    await waitFor(() =>
      expect(signUp).toHaveBeenCalledWith({
        organization_name: 'PT Maju Jaya',
        organization_code: 'MAJU',
        name: 'Rina',
        email: 'rina@maju.test',
        password: 'Rahasia-Kuat-2026',
        password_confirmation: 'Rahasia-Kuat-2026',
      }),
    )
  })
})
