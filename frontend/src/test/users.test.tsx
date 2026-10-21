import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
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

function renderPage(options: UserOrganizationOption[]) {
  vi.mocked(api.get).mockResolvedValue({ data: options })
  vi.mocked(api.post).mockResolvedValue({ data: { id: 'user-1' }, meta: { organization_ids: ['org-a', 'org-b'] } })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <UserCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const type = (label: RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

function fillProfile() {
  type(/Nama lengkap/, 'Sari')
  type(/^Email/, 'sari@grup.test')
  type(/Password sementara/, 'Sementara-Rahasia-1')
}

describe('UserCreatePage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('keeps the single-organization form when only one organization is manageable', async () => {
    renderPage([organizations[0]])
    expect(await screen.findByText(/Role & Permission/)).toBeInTheDocument()
    expect(screen.queryByText(/Akses Organisasi/)).not.toBeInTheDocument()
  })

  it('grants access to several organizations with roles per organization', async () => {
    renderPage(organizations)
    await screen.findByText(/Akses Organisasi & Role/)
    fillProfile()

    fireEvent.click(within(screen.getByRole('group', { name: 'Role di PT Alpha' })).getByLabelText(/Operator/))
    fireEvent.click(screen.getByRole('checkbox', { name: /PT Beta/ }))
    fireEvent.click(within(screen.getByRole('group', { name: 'Role di PT Beta' })).getByLabelText(/Auditor/))
    fireEvent.click(screen.getByRole('button', { name: 'Simpan pengguna' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        '/users',
        expect.objectContaining({
          email: 'sari@grup.test',
          memberships: [
            { organization_id: 'org-a', role_ids: ['op-a'] },
            { organization_id: 'org-b', role_ids: ['au-b'] },
          ],
        }),
      ),
    )
  })

  it('requires a role in every selected organization and at least one organization', async () => {
    renderPage(organizations)
    await screen.findByText(/Akses Organisasi & Role/)
    fillProfile()

    fireEvent.click(screen.getByRole('button', { name: 'Simpan pengguna' }))
    expect(await screen.findByText('Pilih minimal satu role.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('checkbox', { name: /PT Alpha/ }))
    expect(await screen.findByText('Pilih minimal satu organisasi.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })
})
