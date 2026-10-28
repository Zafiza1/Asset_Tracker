import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RoleFormPage } from '../features/roles/RoleFormPage'
import { UserDetailPage } from '../features/users/UserDetailPage'
import { api, ApiError } from '../lib/api/client'
import type { Role, User } from '../lib/api/types'

// SEC-03/04: rejected access changes must surface the server's message and never look like they succeeded.

vi.mock('../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/client')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }
})
vi.mock('../lib/auth/context', () => ({
  useAuth: () => ({ can: () => true, profile: { user: { id: 'me' }, organizations: [{ id: 'org-a' }] } }),
}))

const SCOPE_ESCALATION = new ApiError(403, 'SCOPE_ESCALATION', 'Anda tidak dapat memberikan cakupan data di luar cakupan Anda sendiri.')

const user: User = {
  id: 'u-1', name: 'Budi', email: 'budi@alpha.test', status: 'active', employee_number: null, job_title: 'Staf', phone: null,
  home_department_id: null, must_change_password: false, last_login_at: null, created_at: '2026-10-01T00:00:00Z', updated_at: '2026-10-01T00:00:00Z',
  roles: [{ id: 'r-vw', code: 'SUPERVISOR', name: 'Supervisor' }],
  data_scopes: [{ scope_type: 'location', ref_id: 'loc-a' }],
}
const role: Role = {
  id: 'r-ed', code: 'EDITOR', name: 'Editor', description: null, template_code: null, is_locked: false, users_count: 2,
  permissions: ['asset.view'], created_at: '2026-10-01T00:00:00Z', updated_at: '2026-10-01T00:00:00Z',
}

/** `current` is what the server holds for the user (the page refetches after a change). */
function mockReads(current: () => User = () => user) {
  vi.mocked(api.get).mockImplementation(async (path: string) => {
    if (path === '/users/u-1') return { data: current() }
    if (path.startsWith('/locations')) return { data: [{ id: 'loc-a', code: 'GDA', name: 'Gedung A' }] }
    if (path.startsWith('/departments')) return { data: [] }
    if (path === '/roles') return { data: [{ ...role, id: 'r-vw', code: 'SUPERVISOR', name: 'Supervisor' }, role] }
    if (path === '/roles/r-ed') return { data: role }
    if (path === '/permissions') return { data: [{ code: 'asset.view', group: 'asset', description: 'Lihat aset' }] }
    throw new Error(`unexpected GET ${path}`)
  })
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/pengguna/:id" element={<UserDetailPage />} />
          <Route path="/pengguna/role/:id" element={<RoleFormPage />} />
          <Route path="/pengguna/role" element={<p>daftar role</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const click = (name: string | RegExp) => fireEvent.click(screen.getAllByRole('button', { name, hidden: true }).at(-1)!)

describe('UserDetailPage: rejected access changes', () => {
  beforeEach(() => (vi.clearAllMocks(), mockReads()))

  it('shows SCOPE_ESCALATION when a scope is granted and keeps the old scope', async () => {
    vi.mocked(api.put).mockRejectedValue(SCOPE_ESCALATION)
    renderAt('/pengguna/u-1')
    await screen.findByText('Cakupan data')
    fireEvent.change(screen.getByLabelText('Jenis cakupan'), { target: { value: 'organization' } })
    click('Tambah cakupan')

    expect(await screen.findByRole('alert')).toHaveTextContent(SCOPE_ESCALATION.message)
    expect(api.put).toHaveBeenCalledWith('/users/u-1/scopes', { scopes: [...user.data_scopes!, { scope_type: 'organization', ref_id: null }] })
    expect(screen.queryByText('Seluruh organisasi', { selector: 'span' })).not.toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('keeps the role editor open with the error when a role change is refused', async () => {
    vi.mocked(api.put).mockRejectedValue(SCOPE_ESCALATION)
    renderAt('/pengguna/u-1')
    await screen.findByRole('button', { name: 'Ubah role' })
    click('Ubah role')
    fireEvent.click(await screen.findByLabelText('Editor'))
    click('Simpan role')

    expect(await screen.findByRole('alert')).toHaveTextContent(SCOPE_ESCALATION.message)
    expect(screen.getByRole('button', { name: 'Simpan role' })).toBeInTheDocument()
  })

  it('keeps the confirmation open with the error when suspending a broader member is refused', async () => {
    vi.mocked(api.post).mockRejectedValue(SCOPE_ESCALATION)
    renderAt('/pengguna/u-1')
    await screen.findByRole('button', { name: 'Tangguhkan' })
    click('Tangguhkan')
    click('Tangguhkan')

    expect(await screen.findByText(SCOPE_ESCALATION.message)).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/users/u-1/suspend')
    expect(screen.getByText(/tidak dapat login sampai diaktifkan kembali/)).toBeInTheDocument()
  })

  it('keeps the profile form open with the error when job data of a broader member is refused', async () => {
    vi.mocked(api.patch).mockRejectedValue(SCOPE_ESCALATION)
    renderAt('/pengguna/u-1')
    await screen.findByRole('button', { name: 'Ubah' })
    click('Ubah')
    fireEvent.change(screen.getByLabelText('Jabatan'), { target: { value: 'Kepala' } })
    click('Simpan')

    expect(await screen.findByRole('alert')).toHaveTextContent(SCOPE_ESCALATION.message)
    expect(screen.getByLabelText('Jabatan')).toHaveValue('Kepala')
  })

  it('shows the new scope and no error when the change is allowed', async () => {
    let saved = user
    mockReads(() => saved)
    vi.mocked(api.put).mockImplementation(async (_path, body) => {
      saved = { ...user, data_scopes: (body as { scopes: User['data_scopes'] }).scopes }
      return { data: saved }
    })
    renderAt('/pengguna/u-1')
    await screen.findByText('Cakupan data')
    fireEvent.change(screen.getByLabelText('Jenis cakupan'), { target: { value: 'organization' } })
    click('Tambah cakupan')

    expect(await screen.findByText('Seluruh organisasi', { selector: 'span' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('RoleFormPage: rejected permission change', () => {
  beforeEach(() => (vi.clearAllMocks(), mockReads()))

  it('stays on the form with the error instead of returning to the list', async () => {
    vi.mocked(api.patch).mockRejectedValue(SCOPE_ESCALATION)
    renderAt('/pengguna/role/r-ed')
    await screen.findByDisplayValue('Editor')
    click('Simpan role')

    expect(await screen.findByRole('alert')).toHaveTextContent(SCOPE_ESCALATION.message)
    expect(api.patch).toHaveBeenCalledWith('/roles/r-ed', { name: 'Editor', description: null, permissions: ['asset.view'] })
    expect(screen.queryByText('daftar role')).not.toBeInTheDocument()
  })

  it('returns to the list when the change is allowed', async () => {
    vi.mocked(api.patch).mockResolvedValue({ data: role })
    renderAt('/pengguna/role/r-ed')
    await screen.findByDisplayValue('Editor')
    click('Simpan role')

    await waitFor(() => expect(screen.getByText('daftar role')).toBeInTheDocument())
  })
})
