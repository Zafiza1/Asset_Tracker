import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import { compactFilters } from '../features/reportDesigner/api'
import { ReportRunPage } from '../features/reportDesigner/ReportRunPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const ID = '01900000-0000-7000-8000-0000000000bb'

const TEMPLATE = {
  id: ID,
  code: 'RPT-TRX-KELUAR',
  name: 'Laporan Transaksi Keluar',
  description: null,
  category: 'Transaksi',
  data_source: 'asset_transaction',
  status: 'published',
  active_version: 2,
  created_at: '2026-11-01T00:00:00Z',
  updated_at: '2026-11-01T00:00:00Z',
}

const VERSIONS = {
  data: [
    {
      id: 'v2',
      template_id: ID,
      version_number: 2,
      status: 'published',
      design: { schema: 1, page: { size: 'A4', orientation: 'landscape', marginTop: 10, marginRight: 10, marginBottom: 10, marginLeft: 10 }, elements: [] },
      excel_config: {},
      change_note: null,
      published_at: '2026-11-02T00:00:00Z',
      created_at: '2026-11-01T00:00:00Z',
      updated_at: '2026-11-01T00:00:00Z',
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
}

const FILTERS = {
  data: {
    data_source: 'asset_transaction',
    defaults: { type: 'outgoing' },
    max_rows: 500,
    fields: [
      { key: 'date_from', label: 'Dari tanggal', type: 'date', range: 'date' },
      { key: 'date_to', label: 'Sampai', type: 'date', range: 'date' },
      { key: 'type', label: 'Jenis transaksi', type: 'select', options: [{ value: 'outgoing', label: 'Transaksi keluar' }, { value: 'incoming', label: 'Transaksi masuk' }] },
      { key: 'not_returned', label: 'Hanya yang belum kembali', type: 'boolean' },
    ],
  },
}

function preview(total: number) {
  return {
    data: {
      template: { id: ID, code: TEMPLATE.code, name: TEMPLATE.name, data_source: 'asset_transaction' },
      version: { id: 'v2', version_number: 2, status: 'published' },
      valid: true,
      issues: [],
      context: {},
      rows: [],
      total,
      restricted_fields: [],
      filters: { type: 'outgoing' },
      filter_description: ['Jenis: Transaksi keluar'],
      max_rows: 500,
      elements: [
        { id: 'title', type: 'text', x: 0, y: 0, w: 100, h: 10, z: 1, style: {}, visible: true, text: 'LAPORAN TRANSAKSI KELUAR' },
        {
          id: 'table',
          type: 'table',
          x: 0,
          y: 12,
          w: 200,
          h: 60,
          z: 2,
          style: {},
          visible: true,
          columns: [{ key: 'transaction.number', label: 'No. Transaksi', width: null, format: null }],
          rows: [{ 'transaction.number': 'OUT-2026-000042' }],
        },
      ],
    },
  }
}

const HISTORY = {
  data: [
    {
      id: 'e1',
      export_number: 'RPT-2026-000018',
      format: 'pdf',
      status: 'completed',
      downloadable: true,
      filters: {},
      filter_description: ['Jenis: Transaksi keluar'],
      row_count: 12,
      file_size: 1000,
      version_number: 2,
      created_by: 'Rina',
      created_at: '2026-11-17T03:21:00Z',
      expires_at: '2026-12-17T03:21:00Z',
    },
    {
      id: 'e0',
      export_number: 'RPT-2026-000004',
      format: 'xlsx',
      status: 'expired',
      downloadable: false,
      filters: {},
      filter_description: [],
      row_count: 9,
      file_size: 900,
      version_number: 1,
      created_by: 'Rina',
      created_at: '2026-10-01T03:21:00Z',
      expires_at: '2026-10-31T03:21:00Z',
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 10, total: 2, from: 1, to: 2 },
}

function stub({ total = 12, filtersStatus = 200 }: { total?: number; filtersStatus?: number } = {}) {
  const fetchMock = vi.fn(async (input: string | URL | Request, init?: RequestInit) => {
    const url = String(input)
    const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
    if (url === `/api/report-templates/${ID}`) return json({ data: TEMPLATE })
    if (url.startsWith(`/api/report-templates/${ID}/versions`)) return json(VERSIONS)
    if (url === `/api/report-templates/${ID}/filters`) {
      return filtersStatus === 200 ? json(FILTERS) : json({ error: { code: 'TEMPLATE_NOT_PUBLISHED', message: 'Terbitkan template terlebih dahulu.' } }, filtersStatus)
    }
    if (url === `/api/report-templates/${ID}/run-preview`) return json(preview(total))
    if (url === `/api/report-templates/${ID}/exports` && init?.method === 'POST') return json({ data: HISTORY.data[0] }, 201)
    if (url.startsWith('/api/report-exports')) return json(HISTORY)
    return json({ data: [] })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function authState(permissions: string[]): AuthState {
  return {
    profile: { permissions } as unknown as Profile,
    isLoading: false,
    can: (p: string) => permissions.includes(p),
    login: vi.fn(),
    signUp: vi.fn(),
    logout: vi.fn(),
    setProfile: vi.fn(),
    switchOrganization: vi.fn(),
    createOrganization: vi.fn(),
  }
}

function renderPage(permissions = ['report.view', 'report.generate', 'report.download']) {
  document.cookie = 'XSRF-TOKEN=test'
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={authState(permissions)}>
        <MemoryRouter initialEntries={[`/laporan/template/${ID}/jalankan`]}>
          <Routes>
            <Route path="/laporan/template/:id/jalankan" element={<ReportRunPage />} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

function previewBodies(fetchMock: ReturnType<typeof stub>) {
  return fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/run-preview')).map(([, init]) => JSON.parse(String(init?.body)))
}

describe('ReportRunPage', () => {
  it('previews the active version with the template defaults and lists the history', async () => {
    const fetchMock = stub()
    renderPage()

    expect(await screen.findByText('LAPORAN TRANSAKSI KELUAR')).toBeInTheDocument()
    expect(screen.getByText('OUT-2026-000042')).toBeInTheDocument()
    expect(previewBodies(fetchMock)[0]).toEqual({ filters: { type: 'outgoing' } })
    expect((screen.getByLabelText('Jenis transaksi') as HTMLSelectElement).value).toBe('outgoing')

    expect(await screen.findByText('RPT-2026-000018')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Unduh$/ })).toBeInTheDocument()
    expect(screen.getByText('Kedaluwarsa')).toBeInTheDocument()
  })

  it('applies changed filters and refuses an end date before the start', async () => {
    const fetchMock = stub()
    renderPage()
    await screen.findByText('LAPORAN TRANSAKSI KELUAR')

    fireEvent.change(screen.getByLabelText('Dari tanggal'), { target: { value: '2026-11-10' } })
    fireEvent.change(screen.getByLabelText('Sampai'), { target: { value: '2026-11-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Terapkan filter' }))
    expect(await screen.findByText('Tanggal akhir tidak boleh sebelum tanggal awal.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Unduh PDF/ })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Sampai'), { target: { value: '2026-11-30' } })
    fireEvent.click(screen.getByLabelText('Hanya yang belum kembali'))
    fireEvent.click(screen.getByRole('button', { name: 'Terapkan filter' }))
    await waitFor(() =>
      expect(previewBodies(fetchMock).at(-1)).toEqual({ filters: { type: 'outgoing', date_from: '2026-11-10', date_to: '2026-11-30', not_returned: true } }),
    )
  })

  it('generates a PDF with the applied filters', async () => {
    const fetchMock = stub()
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    renderPage()
    await screen.findByText('LAPORAN TRANSAKSI KELUAR')

    fireEvent.click(screen.getByRole('button', { name: /Unduh PDF/ }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([u, init]) => String(u).endsWith('/exports') && init?.method === 'POST')).toBe(true))
    const [, init] = fetchMock.mock.calls.find(([u, i]) => String(u).endsWith('/exports') && i?.method === 'POST')!
    expect(JSON.parse(String(init?.body))).toEqual({ format: 'pdf', filters: { type: 'outgoing' } })
  })

  it('warns and blocks downloads above the row limit', async () => {
    stub({ total: 1284 })
    renderPage()
    expect(await screen.findByText('Menampilkan 500 dari 1.284 baris')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Unduh Excel/ })).toBeDisabled()
  })

  it('explains that a draft template must be published first', async () => {
    stub({ filtersStatus: 409 })
    renderPage()
    expect(await screen.findByText('Template belum diterbitkan')).toBeInTheDocument()
  })
})

describe('reports menu', () => {
  it('no longer has the fixed asset report; templates are the reports', () => {
    const items = tenantNavigation.flatMap((s) => s.items)
    expect(items.find((i) => i.to === '/laporan')).toBeUndefined()
    expect(items.find((i) => i.to === '/laporan/template')?.label).toBe('Template Laporan')
  })

  it('sends only filled filters', () => {
    expect(compactFilters({ type: 'outgoing', search: '', not_returned: false, date_from: undefined })).toEqual({ type: 'outgoing' })
  })
})
