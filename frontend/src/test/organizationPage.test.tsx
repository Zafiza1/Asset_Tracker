import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { OrganizationPage } from '../features/organization/OrganizationPage'
import { describeCurrency, describeTimezone, formatNpwp, profileCompleteness } from '../features/organization/profileFormat'
import type { Organization, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const org: Organization = {
  id: '01900000-0000-7000-8000-000000000001',
  code: 'PTSIG',
  name: 'PT SIG',
  legal_name: 'PT Sinar Indah Gemilang',
  status: 'active',
  timezone: 'Asia/Jakarta',
  locale: 'id',
  currency: 'IDR',
  email: null,
  phone: null,
  tax_id: null,
  address: 'Jl. Sudirman 1',
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-10-31T03:00:00Z',
}

const SUPER_ADMIN = ['organization.view', 'organization.manage']

function renderPage(permissions: string[]) {
  let current = { ...org }
  const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
    if (init?.method === 'PUT') current = { ...current, ...JSON.parse(String(init.body)) }
    return new Response(JSON.stringify({ data: current }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <OrganizationPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return { fetchMock }
}

const putBody = (fetchMock: ReturnType<typeof vi.fn>) => {
  const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
  return put ? JSON.parse(String((put[1] as RequestInit).body)) : null
}

describe('profile formatting', () => {
  it('counts completeness over every profile field', () => {
    expect(profileCompleteness(org)).toMatchObject({ total: 8, filled: 5, percent: 63 })
    expect(profileCompleteness(org).missing.map((f) => f.label)).toEqual(['NPWP', 'Email', 'Telepon'])
  })

  it('formats NPWP, time zone and currency', () => {
    expect(formatNpwp('012345678901234')).toBe('01.234.567.8-901.234')
    expect(formatNpwp('3201012345678901')).toBe('3201 0123 4567 8901')
    expect(describeTimezone('Asia/Jakarta')).toBe('Asia/Jakarta (WIB)')
    expect(describeTimezone('Asia/Makassar')).toBe('Asia/Makassar (WITA)')
    expect(describeTimezone('Asia/Jayapura')).toBe('Asia/Jayapura (WIT)')
    expect(describeCurrency('IDR')).toBe('IDR (Rupiah Indonesia)')
  })
})

describe('OrganizationPage', () => {
  it('shows completeness, per-section gaps and informative regional values', async () => {
    renderPage(SUPER_ADMIN)
    const progress = await screen.findByRole('progressbar', { name: 'Kelengkapan profil' })
    expect(progress).toHaveAttribute('aria-valuenow', '63')
    expect(screen.getByText('63%')).toBeInTheDocument()
    expect(screen.getByText(/5 dari 8 informasi telah dilengkapi/)).toBeInTheDocument()
    expect(screen.getByText('1 belum diisi')).toBeInTheDocument()
    expect(screen.getByText('2 belum diisi')).toBeInTheDocument()
    expect(screen.getByText('Lengkap')).toBeInTheDocument()
    expect(screen.getAllByText('Belum diisi')).toHaveLength(3)
    expect(screen.getByText('Asia/Jakarta (WIB)')).toBeInTheDocument()
    expect(screen.getByText('IDR (Rupiah Indonesia)')).toBeInTheDocument()
    expect(screen.getByText('Diperbarui 31 Okt 2026', { exact: false })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Lengkapi sekarang' })).toBeInTheDocument()
    expect(screen.getByText(/Anda dapat mengedit profil organisasi/)).toBeInTheDocument()
  })

  it('is read-only for every role but Super Admin', async () => {
    renderPage(['organization.view'])
    expect(await screen.findByText(/Hanya Super Admin yang dapat mengubahnya/)).toBeInTheDocument()
    expect(screen.getByText(/Hubungi Super Admin organisasi/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit profil' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Lengkapi sekarang' })).not.toBeInTheDocument()
  })

  it('rejects malformed email, phone and NPWP before sending', async () => {
    const { fetchMock } = renderPage(SUPER_ADMIN)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit profil' }))
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'bukan-email' } })
    fireEvent.change(screen.getByLabelText('Telepon'), { target: { value: '1111111' } })
    fireEvent.change(screen.getByLabelText('NPWP'), { target: { value: '12.345' } })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }))

    expect(await screen.findByText(/Format email tidak valid/)).toBeInTheDocument()
    expect(screen.getByText(/Nomor telepon diawali 0 atau \+62/)).toBeInTheDocument()
    expect(screen.getByText(/NPWP harus 15 digit/, { selector: 'p[id$="-error"]' })).toBeInTheDocument()
    expect(putBody(fetchMock)).toBeNull()
  })

  it('normalizes input, confirms the save and refreshes the profile', async () => {
    const { fetchMock } = renderPage(SUPER_ADMIN)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit profil' }))
    fireEvent.change(screen.getByLabelText(/^Nama/, { selector: 'input[placeholder="PT SIG"]' }), { target: { value: '  PT   SIG ' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: ' Halo@SIG.co.id ' } })
    fireEvent.change(screen.getByLabelText('Telepon'), { target: { value: '+62 812-3456-7890' } })
    fireEvent.change(screen.getByLabelText('NPWP'), { target: { value: '01.234.567.8-901.234' } })
    fireEvent.change(screen.getByLabelText(/Zona waktu/), { target: { value: 'Asia/Makassar' } })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }))

    expect(await screen.findByText('Profil organisasi berhasil diperbarui')).toBeInTheDocument()
    expect(putBody(fetchMock)).toMatchObject({ name: 'PT SIG', email: 'halo@sig.co.id', phone: '+6281234567890', tax_id: '012345678901234', timezone: 'Asia/Makassar', currency: 'IDR' })
    expect(screen.getByText('01.234.567.8-901.234')).toBeInTheDocument()
    expect(screen.getByText('Asia/Makassar (WITA)')).toBeInTheDocument()
    expect(screen.getByText('100%')).toBeInTheDocument()
    expect(screen.getByText('Profil lengkap')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Tutup notifikasi' }))
    await waitFor(() => expect(screen.queryByText('Profil organisasi berhasil diperbarui')).not.toBeInTheDocument())
  })
})
