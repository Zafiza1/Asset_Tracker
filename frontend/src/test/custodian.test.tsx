import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import { AssetFormModal } from '../features/assets/AssetFormModal'
import { AssetsPage } from '../features/assets/AssetsPage'
import type { Asset, CustodianOption } from '../features/assets/api'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

/** F-09: penanggung jawab dan departemen di Data Aset dan formulir aset. */

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const asset = (id: string, name: string, extra: Partial<Asset> = {}): Asset =>
  ({
    id,
    name,
    serial_number: null,
    category_id: 'c1',
    category: { id: 'c1', code: 'IT', name: 'Laptop' },
    modules: [],
    barcode_enabled: false,
    location_id: 'l1',
    location: { id: 'l1', code: 'GDG', name: 'Gudang' },
    custodian_user_id: null,
    custodian: null,
    department_id: null,
    department: null,
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

const sari: CustodianOption = { id: 'u-sari', name: 'Sari Dewi', email: 'sari@contoh.test', job_title: 'Staf Keuangan', home_department: { id: 'd-keu', code: 'KEU', name: 'Keuangan' } }
const departments = [{ id: 'd-keu', code: 'KEU', name: 'Keuangan' }, { id: 'd-it', code: 'IT', name: 'IT' }]

type Route = (url: string, init?: RequestInit) => unknown

function setup(ui: React.ReactNode, route: Route, permissions = ['asset.view', 'asset.create', 'asset.update']) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => new Response(JSON.stringify(route(String(url), init) ?? { data: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={['/aset']}>{ui}</MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

const page = (rows: Asset[]) => ({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } })

describe('Data Aset: penanggung jawab', () => {
  const laptop = asset('a1', 'Laptop Keuangan', { custodian_user_id: 'u-sari', custodian: { id: 'u-sari', name: 'Sari Dewi', is_active: true }, department: { id: 'd-keu', code: 'KEU', name: 'Keuangan' } })
  const printer = asset('a2', 'Printer Lama', { custodian_user_id: 'u-budi', custodian: { id: 'u-budi', name: 'Budi Santoso', is_active: false } })

  it('shows the custodian column with an inactive marker', async () => {
    setup(<AssetsPage />, (url) => (url.startsWith('/api/assets?') || url === '/api/assets' ? page([laptop, printer]) : { data: [] }))
    expect(await screen.findByRole('columnheader', { name: 'Penanggung jawab' })).toBeInTheDocument()
    expect(screen.getAllByText('Sari Dewi').length).toBeGreaterThan(0)
    const row = screen.getByText('Budi Santoso').closest('tr')!
    expect(within(row).getByText('nonaktif')).toBeInTheDocument()
  })

  it('"Aset saya" asks the API for mine=1 and guides when empty', async () => {
    const fetchMock = setup(<AssetsPage />, (url) => (url.includes('mine=1') ? page([]) : url.startsWith('/api/assets') ? page([laptop]) : { data: [] }))
    fireEvent.click(await screen.findByRole('tab', { name: 'Aset saya' }))
    expect(await screen.findByText('Belum ada aset yang Anda pegang')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('mine=1'))).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Lihat semua aset' }))
    expect(await screen.findByText('Laptop Keuangan')).toBeInTheDocument()
  })
})

describe('Formulir aset: penanggung jawab', () => {
  const editing = asset('a1', 'Laptop')

  it('picks a member, suggests their department and sends both', async () => {
    let sent: Record<string, unknown> | null = null
    setup(<AssetFormModal asset={editing} onClose={() => {}} />, (url, init) => {
      if (url.startsWith('/api/assets/custodian-options')) return { data: [sari] }
      if (url.startsWith('/api/departments')) return { data: departments }
      if (init?.method === 'PATCH') {
        sent = JSON.parse(String(init.body))
        return { data: { ...editing, custodian_user_id: 'u-sari', department_id: 'd-keu' } }
      }
      return { data: [] }
    })

    fireEvent.focus(screen.getByRole('combobox', { name: /Penanggung jawab/, hidden: true }))
    fireEvent.click(await screen.findByRole('option', { name: /Sari Dewi/, hidden: true }))
    expect(screen.getByText(/dari departemen/)).toHaveTextContent('Sari Dewi dari departemen Keuangan.')

    await screen.findByRole('option', { name: 'Keuangan (KEU)', hidden: true })
    fireEvent.click(screen.getByRole('button', { name: 'Pakai Keuangan', hidden: true }))
    expect(screen.getByRole('combobox', { name: 'Departemen', hidden: true })).toHaveValue('d-keu')

    fireEvent.click(screen.getByRole('button', { name: 'Simpan', hidden: true }))
    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toMatchObject({ custodian_user_id: 'u-sari', department_id: 'd-keu' })
  })

  it('keeps an archived department instead of clearing it silently', async () => {
    const withOld = asset('a1', 'Laptop', { department_id: 'd-old', department: { id: 'd-old', code: 'GDL', name: 'Gudang Lama' } })
    setup(<AssetFormModal asset={withOld} onClose={() => {}} />, (url) => (url.startsWith('/api/departments') ? { data: departments } : { data: [] }))
    await screen.findByRole('option', { name: 'Keuangan (KEU)', hidden: true })
    expect(screen.getByRole('combobox', { name: 'Departemen', hidden: true })).toHaveValue('d-old')
    expect(screen.getByRole('option', { name: /Gudang Lama .*diarsipkan/, hidden: true })).toBeInTheDocument()
  })
})

describe('menu', () => {
  it('shows Departemen under Master Data again (D-56)', () => {
    const master = tenantNavigation.find((s) => s.title === 'Master Data')!
    expect(master.items.map((i) => i.to)).toContain('/master/departemen')
  })
})
