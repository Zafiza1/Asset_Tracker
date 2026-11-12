import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { UsersPage } from '../features/users/UsersPage'
import type { Profile, User } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const ADMIN = ['user.view', 'user.update', 'user.deactivate', 'role.view', 'role.manage']

const user = (id: string, name: string, extra: Partial<User> = {}): User => ({
  id,
  name,
  email: `${name.toLowerCase().replace(/\s+/g, '.')}@contoh.id`,
  status: 'active',
  employee_number: null,
  job_title: null,
  phone: null,
  home_department_id: null,
  must_change_password: false,
  last_login_at: '2026-11-10T07:30:00Z',
  roles: [{ id: 'r-1', code: 'admin', name: 'Administrator' }],
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-11-01T00:00:00Z',
  ...extra,
})

const me = user('u-me', 'Saya Sendiri')
const budi = user('u-budi', 'Budi Santoso', { last_login_at: null })

function renderPage({ rows = [me, budi], lastPage = 1, permissions = ADMIN } = {}) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    if (init?.method && init.method !== 'GET') return new Response(JSON.stringify({ data: rows[0] }), { status: 200 })
    if (String(url).startsWith('/api/roles')) return new Response(JSON.stringify({ data: [{ id: 'r-1', code: 'admin', name: 'Administrator' }, { id: 'r-2', code: 'staff', name: 'Staf' }] }), { status: 200 })
    return new Response(JSON.stringify({ data: rows, meta: { current_page: 1, last_page: lastPage, per_page: 10, total: rows.length * lastPage, from: 1, to: rows.length } }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const profile = { permissions, user: { id: me.id } } as unknown as Profile
  const auth: AuthState = { profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <UsersPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const listCalls = () => fetchMock.mock.calls.map(([url]) => String(url)).filter((u) => u.startsWith('/api/users'))
  return { fetchMock, listCalls }
}

describe('Users page', () => {
  it('shows the status and role filters without opening anything', async () => {
    renderPage()
    expect(await screen.findByLabelText('Filter status')).toBeInTheDocument()
    expect(await screen.findByRole('option', { name: 'Staf' })).toBeInTheDocument()
  })

  it('filters by role', async () => {
    const { listCalls } = renderPage()
    await screen.findByRole('option', { name: 'Staf' })
    fireEvent.change(screen.getByLabelText('Filter role'), { target: { value: 'r-2' } })
    fireEvent.click(screen.getByRole('button', { name: 'Terapkan filter' }))
    await waitFor(() => expect(listCalls().some((u) => u.includes('role_id=r-2'))).toBe(true))
    expect(await screen.findByText('Role: Staf')).toBeInTheDocument()
  })

  it('clears the search with one click', async () => {
    const { listCalls } = renderPage()
    const box = await screen.findByLabelText('Cari pengguna')
    fireEvent.change(box, { target: { value: 'budi' } })
    fireEvent.submit(box.closest('form')!)
    await waitFor(() => expect(listCalls().some((u) => u.includes('search=budi'))).toBe(true))
    fireEvent.click(screen.getByRole('button', { name: 'Hapus pencarian' }))
    expect(box).toHaveValue('')
    await waitFor(() => expect(listCalls().at(-1)).not.toContain('search='))
  })

  it('hides paging on a single page and shows the count instead', async () => {
    renderPage()
    expect(await screen.findByText('2 pengguna')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Halaman berikutnya' })).not.toBeInTheDocument()
  })

  it('shows paging when there is more than one page', async () => {
    renderPage({ lastPage: 3 })
    expect(await screen.findByRole('button', { name: 'Halaman berikutnya' })).toBeInTheDocument()
  })

  it('marks accounts that never signed in', async () => {
    renderPage()
    expect((await screen.findAllByText('Belum pernah login')).length).toBeGreaterThan(0)
  })

  it('offers row actions, but no deactivation of the own account', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Tindakan untuk Saya Sendiri' }))
    let menu = screen.getByRole('menu')
    expect(within(menu).getByRole('menuitem', { name: 'Reset password' })).toBeInTheDocument()
    expect(within(menu).queryByRole('menuitem', { name: 'Nonaktifkan' })).not.toBeInTheDocument()
    fireEvent.keyDown(menu, { key: 'Escape' })

    fireEvent.click(screen.getByRole('button', { name: 'Tindakan untuk Budi Santoso' }))
    menu = screen.getByRole('menu')
    fireEvent.click(within(menu).getByRole('menuitem', { name: 'Nonaktifkan' }))
    expect(screen.getByText('Nonaktifkan Budi Santoso?', { selector: 'h2' })).toBeInTheDocument()
  })

  it('shows only the actions the viewer may take', async () => {
    renderPage({ permissions: ['user.view'] })
    fireEvent.click(await screen.findByRole('button', { name: 'Tindakan untuk Budi Santoso' }))
    const items = within(screen.getByRole('menu')).getAllByRole('menuitem').map((i) => i.textContent)
    expect(items).toEqual(['Lihat detail'])
    expect(screen.queryByLabelText('Filter role')).not.toBeInTheDocument()
  })
})
