import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import { AssetFormModal } from '../features/assets/AssetFormModal'
import { AssetsPage } from '../features/assets/AssetsPage'
import type { Asset } from '../features/assets/api'
import { EmployeesPage } from '../features/master/StructurePages'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

/**
 * Edition PT SIG / PT CDO: assets have no penanggung jawab or departemen, Departemen is Divisi (also
 * an employee's position), and vehicles are master data instead of assets.
 */

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
    category: { id: 'c1', code: 'AST_TABUNG', name: 'Aset Tabung' },
    modules: [],
    barcode_enabled: false,
    location_id: null,
    location: null,
    custodian_user_id: 'u-sari',
    custodian: { id: 'u-sari', name: 'Sari Dewi', is_active: true },
    department_id: 'd-keu',
    department: { id: 'd-keu', code: 'KEU', name: 'Keuangan' },
    status: 'available',
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

type Route = (url: string, init?: RequestInit) => unknown

function setup(ui: React.ReactNode, route: Route, path = '/aset') {
  const permissions = ['asset.view', 'asset.create', 'asset.update', 'master.tenant.view', 'master.tenant.create', 'master.tenant.update']
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => new Response(JSON.stringify(route(String(url), init) ?? { data: [] }), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

const page = <T,>(rows: T[]) => ({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } })

describe('Data Aset tanpa penanggung jawab dan departemen', () => {
  it('shows neither column, even when an old saved column list asks for them', async () => {
    setup(<AssetsPage />, (url) => (url.startsWith('/api/assets') ? page([asset('a1', 'Oksigen Medis')]) : { data: [] }), '/aset?columns=name,custodian,department')
    expect((await screen.findAllByText('Oksigen Medis')).length).toBeGreaterThan(0)
    expect(screen.queryByRole('columnheader', { name: 'Penanggung jawab' })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Departemen' })).not.toBeInTheDocument()
    expect(screen.queryByText('Sari Dewi')).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Aset saya' })).not.toBeInTheDocument()
  })

  it('has no penanggung jawab or departemen in the asset form', async () => {
    setup(<AssetFormModal asset={asset('a1', 'Oksigen Medis')} onClose={() => {}} />, () => ({ data: [] }))
    expect(await screen.findByRole('textbox', { name: /Nama Produk|Nama aset/, hidden: true })).toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: /Penanggung jawab/, hidden: true })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Departemen', hidden: true })).not.toBeInTheDocument()
  })
})

describe('Master Data', () => {
  it('has Kendaraan and Divisi; the old Departemen address is gone from the menu', () => {
    const master = tenantNavigation.find((s) => s.title === 'Master Data')!
    const items = master.items.map((i) => [i.to, i.label])
    expect(items).toContainEqual(['/master/kendaraan', 'Kendaraan'])
    expect(items).toContainEqual(['/master/divisi', 'Divisi'])
    expect(master.items.map((i) => i.to)).not.toContain('/master/departemen')
  })

  it('shows the division of an employee as their position', async () => {
    const budi = { id: 'e1', code: 'SIG-0001', name: 'Budi Santoso', status: 'active', department_id: 'd-supir', department: { id: 'd-supir', name: 'Supir', delivery_role: 'driver' },
      phone: null, license_number: null, license_expires_on: null, created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-01T00:00:00Z' }
    setup(<EmployeesPage />, (url) => (url.startsWith('/api/employees') ? page([budi]) : { data: [] }), '/master/karyawan')
    expect(await screen.findByRole('columnheader', { name: /Divisi/ })).toBeInTheDocument()
    expect(screen.getAllByText('Supir').length).toBeGreaterThan(0)
  })
})
