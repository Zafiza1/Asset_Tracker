import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { TenantCategoriesPage } from '../features/master/StructurePages'
import type { Profile, TenantCategory } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  localStorage.clear()
})

/** jsdom has no showModal(), so a dialog is found by its title. */
const dialogTitled = (title: string) => screen.getByText(title, { selector: 'h2' }).closest('dialog') as HTMLElement

const MANAGER = ['master.tenant.view', 'master.tenant.create', 'master.tenant.update', 'master.tenant.archive']

const category = (id: string, code: string, name: string, extra: Partial<TenantCategory> = {}): TenantCategory => ({
  id,
  code,
  name,
  status: 'active',
  description: null,
  parent_id: null,
  parent: null,
  generic_category_id: 'g',
  depth: 0,
  attributes_count: 0,
  inherited_attributes_count: 0,
  children_count: 0,
  assets_count: 0,
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-11-01T00:00:00Z',
  ...extra,
})

const kendaraan = category('01900000-0000-7000-8000-000000000001', 'KENDARAAN', 'Kendaraan', { attributes_count: 9, children_count: 1, assets_count: 4 })
const mobil = category('01900000-0000-7000-8000-000000000002', 'KENDARAAN-MOBIL-OPERASIONAL-DIR', 'Mobil Operasional Direksi', {
  parent_id: kendaraan.id,
  parent: { id: kendaraan.id, code: kendaraan.code, name: kendaraan.name },
  depth: 1,
  attributes_count: 2,
  inherited_attributes_count: 9,
})

function renderPage(permissions = MANAGER, rows = [kendaraan, mobil]) {
  const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
    if (init?.method && init.method !== 'GET') return new Response(JSON.stringify({ data: rows[0] }), { status: 200 })
    return new Response(JSON.stringify({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <TenantCategoriesPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const listCalls = () => fetchMock.mock.calls.map(([url]) => String(url)).filter((u) => u.startsWith('/api/tenant-masters/categories') && !u.includes('all=1'))
  return { fetchMock, listCalls }
}

describe('TenantCategoriesPage', () => {
  it('shows status and actions by default, with tooltips on the action icons', async () => {
    renderPage()
    expect(await screen.findByText('Mobil Operasional Direksi')).toBeInTheDocument()
    // The column picker only chooses the data columns; status and actions are always there.
    expect(screen.getByRole('columnheader', { name: 'Status' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Aksi' })).toBeInTheDocument()

    const edit = screen.getByRole('button', { name: 'Ubah Kendaraan' })
    fireEvent.mouseEnter(edit)
    expect(screen.getByText('Ubah', { selector: 'span' })).toBeInTheDocument()
    fireEvent.mouseLeave(edit)
    expect(screen.queryByText('Ubah', { selector: 'span' })).not.toBeInTheDocument()

    // Long generated codes keep their full value as a tooltip.
    expect(screen.getAllByTitle('KENDARAAN-MOBIL-OPERASIONAL-DIR').length).toBeGreaterThan(0)
  })

  it('counts inherited attributes and opens the attribute list from the count', async () => {
    renderPage()
    const count = await screen.findByRole('button', { name: 'Atribut Mobil Operasional Direksi: 11 atribut (2 milik kategori ini, 9 dari kategori induk)' })
    expect(within(count).getByText('(9 induk)')).toBeInTheDocument()
    fireEvent.click(count)
    expect((await screen.findByText('Atribut Mobil Operasional Direksi', { selector: 'h2' })).closest('dialog')).toBeInTheDocument()
  })

  it('asks before deactivating and says what happens', async () => {
    const { fetchMock } = renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan Kendaraan' }))
    const dialog = dialogTitled('Nonaktifkan Kendaraan?')
    expect(within(dialog).getByText('4 aset yang sudah memakai kategori ini tidak berubah.')).toBeInTheDocument()
    expect(within(dialog).getByText(/1 subkategori di bawahnya tetap aktif/)).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Nonaktifkan', hidden: true }))
    await waitFor(() => expect(screen.queryByRole('dialog', { hidden: true })).not.toBeInTheDocument())
    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')
    expect(patch?.[0]).toBe(`/api/tenant-masters/categories/${kendaraan.id}`)
    expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ status: 'inactive' })
  })

  it('explains up front when a category cannot be archived yet', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Arsipkan Kendaraan' }))
    const dialog = dialogTitled('Arsipkan Kendaraan?')
    expect(within(dialog).getByText(/masih memiliki 1 subkategori/)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Arsipkan', hidden: true })).toBeDisabled()

    fireEvent.click(within(dialog).getAllByRole('button', { name: 'Tutup', hidden: true }).at(-1)!)
    fireEvent.click(screen.getByRole('button', { name: 'Arsipkan Mobil Operasional Direksi' }))
    expect(within(screen.getByRole('dialog', { hidden: true })).getByRole('button', { name: 'Arsipkan', hidden: true })).toBeEnabled()
  })

  it('limits Multiple Edit to the checked categories', async () => {
    const { fetchMock } = renderPage()
    fireEvent.click(await screen.findByRole('checkbox', { name: `Pilih ${mobil.code} ${mobil.name}` }))
    expect(screen.getByText('1 kategori dipilih')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Multiple Edit (1)' }))
    expect(screen.getByText(/1 kategori yang dicentang/)).toBeInTheDocument()

    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }))
    fireEvent.click(screen.getByRole('button', { name: /Download Data/, hidden: true }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/edit-sheet?ids='))).toBe(true))
    expect(fetchMock.mock.calls.find(([url]) => String(url).includes('/edit-sheet'))?.[0]).toBe(`/api/tenant-masters/categories/edit-sheet?ids=${mobil.id}`)
  })

  it('searches as you type and names the parent of each match', async () => {
    const { listCalls } = renderPage()
    expect(await screen.findByText('Mobil Operasional Direksi')).toBeInTheDocument()
    fireEvent.change(screen.getByRole('searchbox', { name: 'Cari Kategori Aset' }), { target: { value: 'mobil' } })
    await waitFor(() => expect(listCalls().some((u) => u.includes('search=mobil'))).toBe(true), { timeout: 2000 })
    expect(await screen.findByText('di bawah Kendaraan')).toBeInTheDocument()
  })
})
