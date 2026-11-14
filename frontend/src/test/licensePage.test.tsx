import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '../features/organization/SettingsPage'
import { groupLicenses } from '../features/organization/license/labels'
import type { LicenseCode, LicenseEntry, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

function InviteProbe() {
  return <p>form undangan: {useLocation().search}</p>
}

/** jsdom has no showModal(), so a dialog is found by its title. */
const dialogTitled = async (title: string) => (await screen.findByText(title, { selector: 'h2' })).closest('dialog') as HTMLElement

const entry = (id: string, extra: Partial<LicenseEntry> = {}): LicenseEntry => ({
  id,
  kind: 'base',
  seats: 1,
  source: 'purchase',
  reference: `PURCHASE-20261101-00${id}`,
  notes: null,
  starts_at: '2026-11-01T00:00:00Z',
  expires_at: '2027-11-01T00:00:00Z',
  revoked_at: null,
  revoke_reason: null,
  granted_by: null,
  status: 'active',
  ...extra,
})

const code = (id: string, value: string, extra: Partial<LicenseCode> = {}): LicenseCode => ({
  id,
  code: value,
  status: 'available',
  assigned_to_user_id: null,
  assigned_user: null,
  assigned_at: null,
  notes: null,
  reference: null,
  license: { id: 'l-1', kind: 'base', source: 'purchase', status: 'active', starts_at: '2026-11-01T00:00:00Z', expires_at: '2027-11-01T00:00:00Z' },
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-11-01T00:00:00Z',
  ...extra,
})

const free = code('c-1', 'LIC-AAAA-1111-0001')
const held = code('c-2', 'LIC-BBBB-2222-0002', { status: 'assigned', assigned_to_user_id: 'u-siti', assigned_user: { id: 'u-siti', name: 'Siti Aminah', email: 'siti@contoh.id' }, assigned_at: '2026-11-05T03:00:00Z' })

function renderPage({ codes = [free, held] }: { codes?: LicenseCode[] } = {}) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const u = String(url)
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: free }), { status: 200 })
    if (u === '/api/license')
      return new Response(
        JSON.stringify({ data: { active: true, over_limit: false, seats: { capacity: 10, active_members: 6, pending_invitations: 1, available: 3 }, licenses: [entry('1'), entry('2'), entry('3', { source: 'trial', reference: 'SUPER_ADMIN_FREE' })] } }),
        { status: 200 },
      )
    if (u.startsWith('/api/license-codes/c-2'))
      return new Response(
        JSON.stringify({
          data: { ...held, history: [{ id: 'h-1', action: 'license_code.released', actor: null, user_name: 'Budi', reason: 'user_deactivated', created_at: '2026-11-04T00:00:00Z' }] },
        }),
        { status: 200 },
      )
    if (u.startsWith('/api/license-codes'))
      return new Response(JSON.stringify({ data: codes, counts: { available: 4, assigned: 5 }, meta: { current_page: 1, last_page: 1, per_page: 10, total: 2, from: 1, to: 2 } }), { status: 200 })
    if (u.includes('csrf-cookie')) return new Response(null, { status: 204 })
    return new Response('{}', { status: 404 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const permissions = ['license.manage', 'settings.manage', 'organization.view', 'user.view', 'user.create', 'role.manage']
  const auth: AuthState = { profile: { permissions, user: { id: 'u-me' } } as unknown as Profile, isLoading: false, can: (p) => permissions.includes(p), login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={['/pengaturan']}>
          <Routes>
            <Route path='/pengaturan' element={<SettingsPage />} />
            <Route path='/pengguna/baru' element={<InviteProbe />} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const codeCalls = () => fetchMock.mock.calls.map(([url]) => String(url)).filter((u) => u.startsWith('/api/license-codes?'))
  return { fetchMock, codeCalls }
}

describe('License page', () => {
  it('tells used seats, people and free seats apart', async () => {
    renderPage()
    const meter = await screen.findByRole('meter', { name: 'Seat terpakai' })
    expect(meter).toHaveAttribute('aria-valuetext', '7 dari 10 seat terpakai')
    expect(screen.getByText('Pengguna aktif')).toBeInTheDocument()
    expect(screen.getByText('Undangan tertunda')).toBeInTheDocument()
    expect(screen.getByText('Seat tersisa')).toBeInTheDocument()
    expect(screen.getByText('3 seat tersisa')).toBeInTheDocument()
  })

  it('filters codes by status tab and search', async () => {
    const { codeCalls } = renderPage()
    const tab = await screen.findByRole('tab', { name: /Siap ditugaskan\s*4/ })
    fireEvent.click(tab)
    await waitFor(() => expect(codeCalls().some((u) => u.includes('status=available'))).toBe(true))

    fireEvent.change(screen.getByLabelText('Cari kode lisensi'), { target: { value: 'siti' } })
    fireEvent.click(screen.getByRole('button', { name: 'Cari' }))
    await waitFor(() => expect(codeCalls().some((u) => u.includes('search=siti'))).toBe(true))
    expect(screen.getByText('Kata kunci: "siti"')).toBeInTheDocument()
  })

  it('sends "Tugaskan ke pengguna" to the invitation form with the code picked', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('LIC-AAAA-1111-0001'))
    expect(screen.queryByRole('columnheader', { name: 'Aksi' })).not.toBeInTheDocument()
    const dialog = await dialogTitled('Kode lisensi LIC-AAAA-1111-0001')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Tugaskan ke pengguna', hidden: true }))
    expect(await screen.findByText('form undangan: ?license_code=c-1')).toBeInTheDocument()
  })

  it('marks a code on an open invitation and does not offer it again', async () => {
    renderPage({ codes: [code('c-3', 'LIC-CCCC-3333-0003', { pending_invitation: { id: 'inv-1', email: 'rudi@contoh.id', expires_at: '2026-11-20T00:00:00Z' } })] })
    expect(await screen.findByText('rudi@contoh.id')).toBeInTheDocument()
    expect(screen.getByText('Menunggu undangan diterima')).toBeInTheDocument()
    fireEvent.click(screen.getByText('LIC-CCCC-3333-0003'))
    const dialog = await dialogTitled('Kode lisensi LIC-CCCC-3333-0003')
    expect(within(dialog).queryByRole('button', { name: 'Tugaskan ke pengguna', hidden: true })).not.toBeInTheDocument()
  })

  it('releases a held code and offers no way to revoke one', async () => {
    const { fetchMock } = renderPage()
    fireEvent.click(await screen.findByText('LIC-BBBB-2222-0002'))
    expect(screen.queryByRole('tab', { name: /Dicabut/ })).not.toBeInTheDocument()
    const detail = await dialogTitled('Kode lisensi LIC-BBBB-2222-0002')
    expect(within(detail).queryByRole('button', { name: /Cabut/, hidden: true })).not.toBeInTheDocument()
    fireEvent.click(within(detail).getByRole('button', { name: 'Lepaskan', hidden: true }))

    const dialog = await dialogTitled('Lepaskan kode lisensi?')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Lepaskan', hidden: true }))
    await waitFor(() => expect(fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')?.[0]).toBe('/api/license-codes/c-2/release'))
  })

  it('shows a code with its license and history', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('LIC-BBBB-2222-0002'))
    const dialog = await dialogTitled('Kode lisensi LIC-BBBB-2222-0002')
    expect(within(dialog).getByText('Siti Aminah')).toBeInTheDocument()
    expect(await within(dialog).findByText('Dilepas otomatis karena Budi dinonaktifkan')).toBeInTheDocument()
    expect(within(dialog).getByText('Kode dibuat')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: /Lepaskan/, hidden: true })).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: /Cabut/, hidden: true })).not.toBeInTheDocument()
  })

  it('groups licenses bought together into one history row', () => {
    const batches = groupLicenses([entry('1'), entry('2'), entry('3', { status: 'expired', expires_at: '2026-11-02T00:00:00Z' })])
    expect(batches).toHaveLength(2)
    expect(batches[0].references).toHaveLength(2)
  })
})
