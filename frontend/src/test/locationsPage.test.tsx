import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LocationsPage } from '../features/master/StructurePages'
import type { Location, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

/** jsdom has no showModal(), so a dialog is found by its title. */
const dialogTitled = (title: string) => screen.getByText(title, { selector: 'h2' }).closest('dialog') as HTMLElement

const MANAGER = ['master.tenant.view', 'master.tenant.create', 'master.tenant.update', 'master.tenant.archive']

const location = (id: string, code: string, name: string, extra: Partial<Location> = {}): Location => ({
  id,
  code,
  name,
  status: 'active',
  address: null,
  parent_id: null,
  parent: null,
  depth: 0,
  children_count: 0,
  assets_count: 0,
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-11-01T00:00:00Z',
  ...extra,
} as Location)

const gedung = location('01900000-0000-7000-8000-000000000011', 'GEDUNG-A', 'Gedung A', { address: 'Jl. Merdeka 10, Jakarta', children_count: 1, assets_count: 3 })
const ruang = location('01900000-0000-7000-8000-000000000012', 'GEDUNG-A-RUANG-SERVER-LANTAI-2', 'Ruang Server', {
  parent_id: gedung.id,
  parent: { id: gedung.id, code: gedung.code, name: gedung.name },
  depth: 1,
})

function renderPage(rows = [gedung, ruang]) {
  const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
    if (init?.method && init.method !== 'GET') return new Response(JSON.stringify({ data: rows[0] }), { status: 200 })
    return new Response(JSON.stringify({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => MANAGER.includes(p)
  const auth: AuthState = { profile: { permissions: MANAGER } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <LocationsPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const listCalls = () => fetchMock.mock.calls.map(([url]) => String(url)).filter((u) => u.startsWith('/api/locations') && !u.includes('all=1'))
  return { fetchMock, listCalls }
}

describe('LocationsPage', () => {
  it('shows address, usage, status and tooltipped actions', async () => {
    renderPage()
    expect(await screen.findByText('Ruang Server')).toBeInTheDocument()
    for (const header of ['Alamat', 'Pemakaian', 'Status', 'Aksi']) expect(screen.getByRole('columnheader', { name: header })).toBeInTheDocument()
    expect(screen.getByText('3 aset')).toBeInTheDocument()
    expect(screen.getByText('1 sub-lokasi')).toBeInTheDocument()
    expect(screen.getAllByTitle('Jl. Merdeka 10, Jakarta').length).toBeGreaterThan(0)
    expect(screen.getAllByTitle('GEDUNG-A-RUANG-SERVER-LANTAI-2').length).toBeGreaterThan(0)

    const edit = screen.getByRole('button', { name: 'Ubah Gedung A' })
    fireEvent.mouseEnter(edit)
    expect(screen.getByText('Ubah', { selector: 'span' })).toBeInTheDocument()
  })

  it('has one add button and Multiple Edit in the page header', async () => {
    renderPage()
    await screen.findByText('Ruang Server')
    expect(screen.getAllByRole('heading', { name: 'Lokasi' })).toHaveLength(1)
    expect(screen.getByRole('button', { name: /Tambah lokasi/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Multiple Edit' })).toBeInTheDocument()
  })

  it('explains up front when a location cannot be archived yet', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Arsipkan Gedung A' }))
    const dialog = dialogTitled('Arsipkan Gedung A?')
    expect(within(dialog).getByText(/masih memiliki 1 sub-lokasi/)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Arsipkan', hidden: true })).toBeDisabled()
  })

  it('asks before deactivating', async () => {
    const { fetchMock } = renderPage([ruang])
    fireEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan Ruang Server' }))
    const dialog = dialogTitled('Nonaktifkan Ruang Server?')
    expect(within(dialog).getByText('Belum ada aset aktif di lokasi ini.')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Nonaktifkan', hidden: true }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(true))
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(patch?.[0]).toBe(`/api/locations/${ruang.id}`)
  })

  it('limits Multiple Edit to the checked locations', async () => {
    const { fetchMock } = renderPage()
    fireEvent.click(await screen.findByRole('checkbox', { name: `Pilih ${ruang.code} ${ruang.name}` }))
    expect(screen.getByText('1 lokasi dipilih')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Multiple Edit (1)' }))
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }))
    fireEvent.click(screen.getByRole('button', { name: /Download Data/, hidden: true }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/edit-sheet?ids='))).toBe(true))
    expect(fetchMock.mock.calls.find(([url]) => String(url).includes('/edit-sheet'))?.[0]).toBe(`/api/locations/edit-sheet?ids=${ruang.id}`)
  })

  it('searches as you type and names the parent of each match', async () => {
    const { listCalls } = renderPage()
    expect(await screen.findByText('Ruang Server')).toBeInTheDocument()
    fireEvent.change(screen.getByRole('searchbox', { name: 'Cari Lokasi' }), { target: { value: 'server' } })
    await waitFor(() => expect(listCalls().some((u) => u.includes('search=server'))).toBe(true), { timeout: 2000 })
    expect(await screen.findByText('di bawah Gedung A')).toBeInTheDocument()
  })
})
