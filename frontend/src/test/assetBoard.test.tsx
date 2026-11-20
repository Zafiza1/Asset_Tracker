import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CatalogEntry, Dashboard, DashboardWidget } from '../features/assetBoard/api'
import { AssetBoardPage } from '../features/assetBoard/AssetBoardPage'
import { WidgetBody } from '../features/assetBoard/WidgetBody'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const widget = (id: string, key: string, title: string, sort: number, extra: Partial<DashboardWidget> = {}): DashboardWidget => ({
  id,
  widget_key: key,
  component_type: 'statistic',
  title,
  custom_title: null,
  configuration: {},
  layout: { w: 3, h: 1 },
  sort_order: sort,
  is_visible: true,
  is_available: true,
  ...extra,
})

const board: Dashboard = {
  id: 'd1',
  name: 'Dashboard Aset',
  description: null,
  dashboard_type: 'asset_board',
  visibility: 'personal',
  template: { id: 't1', name: 'Ringkasan Eksekutif', scope: 'organization', version: 1 },
  can: { edit: true, manage: true, delete: false },
  widgets: [
    widget('w1', 'total_assets', 'Total Aset', 1),
    widget('w2', 'value_by_category', 'Nilai per kategori', 2, { component_type: 'chart', layout: { w: 6, h: 2 } }),
    widget('w3', 'pending_approvals', 'Persetujuan menunggu', 3),
    widget('w4', 'recent_assets', 'Aset terbaru', 4, { component_type: 'table', layout: { w: 12, h: 2 } }),
  ],
}

const entry = (key: string, name: string, permission: string | null, fields: CatalogEntry['configuration_schema']['fields'] = {}): CatalogEntry => ({
  id: `c-${key}`,
  widget_key: key,
  name,
  description: null,
  component_type: 'statistic',
  required_permission: permission,
  configuration_schema: { fields },
  default_layout: { w: 3, h: 1 },
})

const catalog = [
  entry('total_assets', 'Total Aset', 'asset.view'),
  entry('value_by_category', 'Nilai per kategori', 'asset.view'),
  entry('pending_approvals', 'Persetujuan menunggu', 'approval.manage'),
  entry('recent_assets', 'Aset terbaru', 'asset.view'),
  entry('assets_on_loan', 'Aset Dipinjam', 'asset.view', { include_archived: { type: 'boolean', label: 'Termasuk arsip', default: false } }),
]

const widgetData: Record<string, unknown> = {
  w1: { value: 1284, unit: 'count' },
  w2: { chart: 'bar', categories: ['Kendaraan', 'Perangkat IT'], series: [{ key: 'value', label: 'Nilai', data: [3_100_000_000, 2_000_000_000] }] },
  w3: { value: 4, unit: 'count' },
}

function setup({ permissions = ['dashboard.view', 'asset.view'], wide = true } = {}) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'
    const json = (body: unknown, status = 200) => new Response(body === null ? null : JSON.stringify(body), { status })
    if (method !== 'GET') return method === 'DELETE' || url.endsWith('widgets-order') ? json(null, 204) : json({ data: { ...widget('w9', 'assets_on_loan', 'Aset Dipinjam', 9) } }, method === 'POST' ? 201 : 200)
    if (url.includes('/dashboards/navigation')) return json({ data: { categories: [{ category_id: 'k1', parent_id: null, code: 'KND', name: 'Kendaraan', dashboard_id: null }] } })
    if (url.includes('/widget-catalog')) return json({ data: catalog })
    if (url.includes('/dashboards/asset-board')) return json({ data: board })
    const data = url.match(/widgets\/(w\d)\/data/)
    if (data) {
      if (data[1] === 'w4') return json({ error: { code: 'SERVER_ERROR', message: 'Gangguan server.' } }, 500)
      return json({ data: widgetData[data[1]] })
    }
    return json({ data: [] })
  })
  vi.stubGlobal('fetch', fetchMock)
  vi.stubGlobal('matchMedia', (query: string) => ({ matches: wide, media: query, addEventListener: () => {}, removeEventListener: () => {} }))
  document.cookie = 'XSRF-TOKEN=test'
  const auth: AuthState = {
    profile: { user: { id: 'u1', name: 'Rina' }, organization: { name: 'PT Surya', currency: 'IDR' }, permissions } as unknown as Profile,
    isLoading: false,
    can: (p: string) => permissions.includes(p),
    login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn(),
  }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <AssetBoardPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

const titles = () => screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)

describe('Dashboard Aset', () => {
  it('shows each widget with its own data, and leaves out widgets the member may not see', { timeout: 10_000 }, async () => {
    setup()
    expect(await screen.findByText('1.284')).toBeInTheDocument()
    expect(screen.getByText('Template: Ringkasan Eksekutif · salinan pribadi Anda')).toBeInTheDocument()
    expect(titles()).toEqual(['Total Aset', 'Nilai per kategori', 'Aset terbaru'])
    expect(await screen.findByText(/Rp 3,1/)).toBeInTheDocument()
    // One failing widget does not take the others down.
    const failing = screen.getByRole('region', { name: 'Aset terbaru' })
    // A server error is retried before the widget gives up.
    expect(await within(failing).findByText('Widget gagal dimuat', {}, { timeout: 6000 })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Kendaraan' })).toBeInTheDocument()
  })

  it('saves a new order and width from "Atur tata letak"', async () => {
    const fetchMock = setup()
    fireEvent.click(await screen.findByRole('button', { name: 'Atur tata letak' }))
    fireEvent.click(screen.getByRole('button', { name: 'Turunkan Total Aset' }))
    fireEvent.change(screen.getByRole('combobox', { name: 'Lebar Aset terbaru' }), { target: { value: '6' } })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan tata letak' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Simpan tata letak' })).not.toBeInTheDocument())

    const calls = fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET')
    const order = calls.find(([url]) => url.endsWith('/widgets-order'))
    // The widget the member cannot see keeps its place at the end.
    expect(JSON.parse(String(order?.[1]?.body))).toEqual({ order: ['w2', 'w1', 'w4', 'w3'] })
    const patch = calls.find(([url, init]) => init?.method === 'PATCH' && url.endsWith('/widgets/w4'))
    expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ layout: { w: 6, h: 2 } })
  })

  it('offers only permitted widgets in "Tambah widget" and sends the defaults', async () => {
    const fetchMock = setup()
    fireEvent.click(await screen.findByRole('button', { name: 'Tambah widget' }))
    // jsdom does not open <dialog>, so it stays out of the accessibility tree.
    const dialog = (await screen.findByText(/Widget yang membutuhkan izin/)).closest('dialog')!
    expect(within(dialog).queryByRole('button', { name: /Persetujuan menunggu/, hidden: true })).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: /Aset Dipinjam/, hidden: true }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Tambahkan', hidden: true }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true))
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(post?.[0]).toBe('/api/dashboards/d1/widgets')
    expect(JSON.parse(String(post?.[1]?.body))).toEqual({ widget_catalog_id: 'c-assets_on_loan', title: null, configuration: { include_archived: false }, layout: { w: 3, h: 1 } })
  })

  it('is view-only on a phone (D-34)', async () => {
    setup({ wide: false })
    expect(await screen.findByText('1.284')).toBeInTheDocument()
    expect(screen.getByText('Tampilan saja di ponsel')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Atur tata letak' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah widget' })).not.toBeInTheDocument()
  })
})

describe('WidgetBody', () => {
  const renderBody = (payload: Parameters<typeof WidgetBody>[0]['payload']) =>
    render(
      <AuthContext.Provider value={{ can: (p: string) => p === 'asset.view' } as unknown as AuthState}>
        <MemoryRouter>
          <WidgetBody payload={payload} />
        </MemoryRouter>
      </AuthContext.Provider>,
    )

  it('formats money and percent figures', () => {
    renderBody({ value: '8420000000', unit: 'money', currency: 'IDR' })
    expect(screen.getByText(/^Rp 8,4/)).toBeInTheDocument()
  })

  it('renders table cells: asset links, status badges, lists and late days', () => {
    renderBody({
      columns: [
        { key: 'asset', label: 'Aset' },
        { key: 'status', label: 'Status' },
        { key: 'missing', label: 'Kosong' },
        { key: 'days_late', label: 'Terlambat' },
      ],
      rows: [{ asset: { id: 'a1', name: 'Laptop', asset_number: 'AST-1' }, status: { value: 'lost', label: 'Hilang' }, missing: ['No seri', 'Lokasi'], days_late: 8 }],
      count: 3,
    })
    expect(screen.getByRole('link', { name: /Laptop/ })).toHaveAttribute('href', '/aset/a1')
    expect(screen.getByText('Hilang')).toBeInTheDocument()
    expect(screen.getByText('No seri, Lokasi')).toBeInTheDocument()
    expect(screen.getByText('8 hari')).toBeInTheDocument()
    expect(screen.getByText('Menampilkan 1 dari 3')).toBeInTheDocument()
  })

  it('shows only the shortcuts the member may use, and calm empty states', () => {
    renderBody({ items: [{ key: 'assets', label: 'Daftar Aset', target: '/aset', permission: 'asset.view' }, { key: 'reports', label: 'Laporan', target: '/laporan/template', permission: 'report.view' }] })
    expect(screen.getByRole('link', { name: /Daftar Aset/ })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Laporan/ })).not.toBeInTheDocument()
  })

  it('says when a chart has no data', () => {
    renderBody({ chart: 'donut', categories: [], series: [{ key: 'count', label: 'Jumlah', data: [] }] })
    expect(screen.getByText('Belum ada data untuk filter ini.')).toBeInTheDocument()
  })
})
