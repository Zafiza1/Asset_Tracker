import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AssetsPage } from '../features/assets/AssetsPage'
import type { Asset } from '../features/assets/api'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const asset = (id: string, name: string, extra: Partial<Asset> = {}): Asset =>
  ({
    id,
    name,
    serial_number: `SN-${name}`,
    category_id: 'c1',
    category: { id: 'c1', code: 'IT', name: 'Elektronik' },
    modules: [],
    barcode_enabled: false,
    location_id: 'l1',
    location: { id: 'l1', code: 'GDG', name: 'Gudang' },
    status: 'in_use',
    acquisition_date: null,
    acquisition_cost: null,
    description: null,
    attributes: {},
    image_url: null,
    document_url: null,
    document_name: null,
    borrower_name: null,
    loan_due_date: null,
    is_archived: false,
    archived_at: null,
    lock_version: 1,
    created_at: '2026-11-01T00:00:00Z',
    updated_at: '2026-11-01T00:00:00Z',
    ...extra,
  }) as Asset

const laptop = asset('01900000-0000-7000-8000-0000000000a1', 'Laptop')
const printer = asset('01900000-0000-7000-8000-0000000000a2', 'Printer')
const old = asset('01900000-0000-7000-8000-0000000000a3', 'Mesin Lama', { is_archived: true, archived_at: '2026-11-02T00:00:00Z' })

function renderPage(permissions = ['asset.view', 'asset.create', 'asset.update'], rows = [laptop, printer, old]) {
  const fetchMock = vi.fn(async (_url: string) =>
    new Response(JSON.stringify({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } }), { status: 200 }),
  )
  vi.stubGlobal('fetch', fetchMock)
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }))
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={['/aset']}>
          <AssetsPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const editSheetCalls = () => fetchMock.mock.calls.map(([url]) => String(url)).filter((u) => u.includes('/edit-sheet'))
  return { fetchMock, editSheetCalls }
}

const download = () => fireEvent.click(screen.getByRole('button', { name: /Download Data/, hidden: true }))

describe('Multiple Edit on Data Aset (Excel, same flow as Kategori)', () => {
  it('has Multiple Edit in the page header and edits every active asset when nothing is checked', async () => {
    const { editSheetCalls } = renderPage()
    await screen.findByText('Laptop')
    fireEvent.click(screen.getByRole('button', { name: 'Multiple Edit' }))

    expect(screen.getByText('Multiple Edit Aset (Excel)', { selector: 'h2' })).toBeInTheDocument()
    expect(screen.getByText(/semua aset aktif/)).toBeInTheDocument()
    download()
    await waitFor(() => expect(editSheetCalls()).toHaveLength(1))
    expect(editSheetCalls()[0]).toBe('/api/assets/edit-sheet')
  })

  it('limits the sheet to the checked active assets', async () => {
    const { editSheetCalls } = renderPage()
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Pilih Laptop' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih Mesin Lama' }))

    fireEvent.click(screen.getByRole('button', { name: 'Multiple Edit (1)' }))
    expect(screen.getByText(/1 aset yang dicentang; 1 aset nonaktif tidak ikut/)).toBeInTheDocument()
    download()
    await waitFor(() => expect(editSheetCalls()).toHaveLength(1))
    expect(editSheetCalls()[0]).toBe(`/api/assets/edit-sheet?ids=${laptop.id}`)
  })

  it('opens the same Excel flow from the selection bar', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Pilih Printer' }))
    const bar = screen.getByRole('region', { name: 'Aksi untuk aset terpilih' })
    fireEvent.click(bar.querySelector('button') as HTMLElement)

    expect(screen.getByText('Multiple Edit Aset (Excel)', { selector: 'h2' })).toBeInTheDocument()
    expect(screen.getByText(/1 aset yang dicentang/)).toBeInTheDocument()
  })

  it('hides Multiple Edit without asset.update', async () => {
    renderPage(['asset.view'])
    await screen.findByText('Laptop')
    expect(screen.queryByRole('button', { name: /Multiple Edit/ })).not.toBeInTheDocument()
  })
})
