import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { UserCreatePage } from '../features/users/UserCreatePage'
import { api } from '../lib/api/client'
import type { UserOrganizationOption } from '../lib/api/types'

vi.mock('../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/client')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn() } }
})

const role = (id: string, name: string) => ({ id, code: id.toUpperCase(), name, description: null, permissions_count: 3 })
const organizations: UserOrganizationOption[] = [
  { id: 'org-a', code: 'ALPHA', name: 'PT Alpha', is_current: true, roles: [role('op-a', 'Operator'), role('vw-a', 'Viewer')] },
  { id: 'org-b', code: 'BETA', name: 'PT Beta', is_current: false, roles: [role('op-b', 'Operator'), role('au-b', 'Auditor')] },
]

const licenseCodeId = '01a18ab7-0000-7000-8000-000000000001'

function renderPage(options: UserOrganizationOption[]) {
  vi.mocked(api.get).mockImplementation(async (path: string) => {
    if (path.startsWith('/license-codes')) return { data: [{ id: licenseCodeId, code: 'LIC-AAAA-BBBB-CCCC', notes: null }] }
    if (path === '/permissions') return { data: [] }
    return { data: options }
  })
  vi.mocked(api.post).mockResolvedValue({ data: { id: 'inv-1' }, meta: { organization_ids: ['org-a', 'org-b'] } })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/pengguna/baru']}>
        <Routes>
          <Route path="/pengguna/baru" element={<UserCreatePage />} />
          <Route path="/pengguna/undangan" element={<p>daftar undangan</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const type = (label: RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('UserCreatePage (invite)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('keeps the single-organization form when only one organization is manageable', async () => {
    renderPage([organizations[0]])
    expect(await screen.findByText(/Role & Permission/)).toBeInTheDocument()
    expect(screen.queryByText(/Akses Organisasi/)).not.toBeInTheDocument()
  })

  it('asks for neither a name nor a password: the invitee provides them', async () => {
    renderPage(organizations)
    await screen.findByText(/Akses Organisasi & Role/)
    expect(screen.queryByLabelText(/Password/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Nama lengkap/)).not.toBeInTheDocument()
  })

  it('invites to several organizations with roles per organization, then shows the invitations', async () => {
    renderPage(organizations)
    await screen.findByText(/Akses Organisasi & Role/)
    type(/^Email/, 'sari@grup.test')
    const licenseCode = screen.getByLabelText(/^Kode Lisensi/)
    await within(licenseCode).findByRole('option', { name: /LIC-AAAA-BBBB-CCCC/ })
    fireEvent.change(licenseCode, { target: { value: licenseCodeId } })

    // One role per organization, in the order of the organization list.
    fireEvent.change(screen.getAllByLabelText(/^Role/)[0], { target: { value: 'op-a' } })
    fireEvent.click(screen.getByRole('checkbox', { name: /PT Beta/ }))
    fireEvent.change(screen.getAllByLabelText(/^Role/)[1], { target: { value: 'au-b' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kirim undangan' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/invitations', {
        email: 'sari@grup.test',
        employee_number: '',
        job_title: '',
        license_code_id: licenseCodeId,
        memberships: [
          { organization_id: 'org-a', role_ids: ['op-a'] },
          { organization_id: 'org-b', role_ids: ['au-b'] },
        ],
        scopes: [{ scope_type: 'organization' }],
      }),
    )
    expect(api.post).not.toHaveBeenCalledWith('/users', expect.anything())
    expect(await screen.findByText('daftar undangan')).toBeInTheDocument()
  })

  it('requires a role in every selected organization and at least one organization', async () => {
    renderPage(organizations)
    await screen.findByText(/Akses Organisasi & Role/)
    type(/^Email/, 'sari@grup.test')

    fireEvent.click(screen.getByRole('button', { name: 'Kirim undangan' }))
    expect((await screen.findAllByText('Pilih minimal satu role.')).length).toBeGreaterThan(0)

    fireEvent.click(screen.getByRole('checkbox', { name: /PT Alpha/ }))
    expect(await screen.findByText('Pilih minimal satu organisasi.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })
})
