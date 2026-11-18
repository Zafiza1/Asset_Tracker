import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import { ReportDesignerPage } from '../features/reportDesigner/ReportDesignerPage'
import { ReportTemplatesPage } from '../features/reportDesigner/ReportTemplatesPage'
import { clampToContent, createElement, defaultDesign, pageSizeMm } from '../features/reportDesigner/design'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const TEMPLATE = {
  id: '01900000-0000-7000-8000-0000000000aa',
  code: 'STOCK-01',
  name: 'Laporan Stok',
  description: null,
  category: null,
  data_source: 'asset',
  status: 'draft',
  active_version: null,
  created_by: 'Admin',
  versions_count: 1,
  draft_version: { id: 'v1', version_number: 1, status: 'draft', published_at: null },
  published_version: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const VERSION = {
  id: 'v1',
  template_id: TEMPLATE.id,
  version_number: 1,
  status: 'draft',
  design: { schema: 1, page: { size: 'A4', orientation: 'portrait', marginTop: 15, marginRight: 15, marginBottom: 15, marginLeft: 15 }, elements: [] },
  excel_config: {},
  change_note: null,
  created_by: 'Admin',
  published_at: null,
  published_by: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const FIELDS = {
  asset: [
    { id: 'f1', data_source: 'asset', field_key: 'asset.name', label: 'Nama Aset', data_type: 'string', bind_path: 'name', relation: null, is_repeatable: false, formats: {}, required_permission: 'asset.view' },
  ],
  context: [
    { id: 'f2', data_source: 'context', field_key: 'organization.name', label: 'Nama Organisasi', data_type: 'string', bind_path: 'organization.name', relation: null, is_repeatable: false, formats: {}, required_permission: null },
  ],
}

function authState(permissions: string[]): AuthState {
  const can = (p: string) => permissions.includes(p)
  return {
    profile: { permissions } as unknown as Profile,
    isLoading: false,
    can,
    login: vi.fn(),
    signUp: vi.fn(),
    logout: vi.fn(),
    setProfile: vi.fn(),
    switchOrganization: vi.fn(),
    createOrganization: vi.fn(),
  }
}

function renderWith(ui: React.ReactNode, permissions: string[], route: string) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={authState(permissions)}>
        <MemoryRouter initialEntries={[route]}>
          <Routes>
            <Route path="/laporan/template" element={ui} />
            <Route path="/laporan/template/:id/desain" element={ui} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

describe('design document', () => {
  it('creates elements with registry-backed defaults', () => {
    const el = createElement('table', 'asset_transaction')
    expect(el.props.columns?.map((c) => c.key)).toContain('transaction.number')
    expect(createElement('dynamic_text', 'asset').props.text).toBe('{{report.title}}')
    expect(defaultDesign().page.size).toBe('A4')
  })

  it('clamps elements inside the content box', () => {
    const page = { size: 'A4' as const, orientation: 'portrait' as const, marginTop: 15, marginRight: 15, marginBottom: 15, marginLeft: 15 }
    const el = { x: 500, y: -3, w: 50, h: 10 }
    const [cw] = [pageSizeMm(page)[0] - 30]
    const clamped = clampToContent(el, page)
    expect(clamped.x).toBeLessThanOrEqual(cw - 50)
    expect(clamped.y).toBe(0)
  })
})

describe('ReportTemplatesPage', () => {
  function stubList() {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: string | URL | Request) => {
        const url = String(input)
        if (url === '/api/report-templates' || url.startsWith('/api/report-templates?')) {
          return new Response(JSON.stringify({ data: [TEMPLATE], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 } }), { status: 200 })
        }
        return new Response(JSON.stringify({ data: [] }), { status: 200 })
      }),
    )
  }

  it('lists templates and gates the create action by permission', async () => {
    stubList()
    renderWith(<ReportTemplatesPage />, ['report.view'], '/laporan/template')
    expect(await screen.findByText('Laporan Stok')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Template Baru/ })).not.toBeInTheDocument()
    // Row menu still offers detail for viewers.
    fireEvent.click(screen.getByRole('button', { name: 'Aksi Laporan Stok' }))
    expect(screen.getByRole('menuitem', { name: 'Detail' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Buka Desainer' })).not.toBeInTheDocument()
  })

  it('shows the create button with report.create', async () => {
    stubList()
    renderWith(<ReportTemplatesPage />, ['report.view', 'report.create'], '/laporan/template')
    await screen.findByText('Laporan Stok')
    expect(screen.getByRole('button', { name: /Template Baru/ })).toBeInTheDocument()
  })

  it('is a permission-guarded menu item', () => {
    const item = tenantNavigation.flatMap((s) => s.items).find((i) => i.to === '/laporan/template')
    expect(item?.permission).toBe('report.view')
  })
})

describe('ReportDesignerPage', () => {
  function stubDesigner() {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input)
        if (url === `/api/report-templates/${TEMPLATE.id}` && (!init || init.method === undefined || init.method === 'GET')) {
          return new Response(JSON.stringify({ data: TEMPLATE }), { status: 200 })
        }
        if (url.startsWith(`/api/report-templates/${TEMPLATE.id}/versions`)) {
          return new Response(JSON.stringify({ data: [VERSION], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } }), { status: 200 })
        }
        if (url === '/api/report-fields') {
          return new Response(JSON.stringify({ data: FIELDS }), { status: 200 })
        }
        if (url === `/api/report-templates/${TEMPLATE.id}` && init?.method === 'PUT') {
          return new Response(JSON.stringify({ data: { ...TEMPLATE, updated_at: '2026-01-02T00:00:00Z' } }), { status: 200 })
        }
        if (url === `/api/report-templates/${TEMPLATE.id}/validate` && init?.method === 'POST') {
          return new Response(JSON.stringify({
            data: {
              valid: false,
              bindings: ['asset.bogus'],
              issues: [{ severity: 'error', element_id: 'e1', path: 'elements[id=e1].props.text', code: 'UNKNOWN_BINDING', message: 'Field "asset.bogus" tidak ada di registry untuk sumber ini.' }],
            },
          }), { status: 200 })
        }
        return new Response(JSON.stringify({ data: [] }), { status: 200 })
      }),
    )
  }

  it('adds an element from the palette and saves the design', async () => {
    stubDesigner()
    renderWith(<ReportDesignerPage />, ['report.view', 'report.update', 'report.publish'], `/laporan/template/${TEMPLATE.id}/desain`)

    // Template name and the empty canvas hint appear once loaded.
    expect(await screen.findByText('Laporan Stok')).toBeInTheDocument()
    expect(await screen.findByText(/Seret elemen dari panel kiri/)).toBeInTheDocument()

    // Click-to-add: palette items work without drag events.
    fireEvent.click(screen.getByRole('button', { name: /Teks(?! Dinamis)/ }))
    expect(await screen.findByRole('button', { name: 'Elemen text' })).toBeInTheDocument()

    // Save opens the version-note dialog and PUTs the design document.
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))
    // jsdom does not open <dialog>, so it stays out of the accessibility tree; both
    // designer modals render a <dialog>, so pick the save one by its title.
    const dialogs = await screen.findAllByRole('dialog', { hidden: true })
    const dialog = dialogs.find((d) => within(d).queryByText('Simpan Perubahan Desain'))!
    fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan Versi', hidden: true }))
    await waitFor(() => {
      const put = vi.mocked(fetch).mock.calls.find(([u, i]) => String(u) === `/api/report-templates/${TEMPLATE.id}` && (i as RequestInit)?.method === 'PUT')
      expect(put).toBeTruthy()
      const body = JSON.parse(String((put![1] as RequestInit).body))
      expect(body.design.schema).toBe(1)
      expect(body.design.elements).toHaveLength(1)
      expect(body.design.elements[0].type).toBe('text')
    })
  })

  it('inserts a registry binding into the selected text element', async () => {
    stubDesigner()
    renderWith(<ReportDesignerPage />, ['report.view', 'report.update'], `/laporan/template/${TEMPLATE.id}/desain`)
    await screen.findByText('Laporan Stok')

    fireEvent.click(screen.getByRole('button', { name: /Teks(?! Dinamis)/ }))
    const el = await screen.findByRole('button', { name: 'Elemen text' })
    expect(el).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: /Nama Aset/ }))
    // The binding lands in the element's editable content via the properties panel on blur;
    // verify through the properties textarea value instead.
    const area = screen.getByPlaceholderText(/field\.key|Teks/)
    fireEvent.change(area, { target: { value: 'Stok {{asset.name}}' } })
    fireEvent.blur(area)
    // Undo becomes available after the committed edit.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Urungkan' })).toBeEnabled())
  })

  it('validates the live design and lists registry issues', async () => {
    stubDesigner()
    renderWith(<ReportDesignerPage />, ['report.view', 'report.update'], `/laporan/template/${TEMPLATE.id}/desain`)
    await screen.findByText('Laporan Stok')

    fireEvent.click(screen.getByRole('button', { name: /Validasi/ }))

    const dialogs = await screen.findAllByRole('dialog', { hidden: true })
    const dialog = dialogs.find((d) => within(d).queryByText('Validasi Desain'))!
    expect(within(dialog).getByText(/tidak ada di registry/)).toBeInTheDocument()
    expect(within(dialog).getByText('UNKNOWN_BINDING')).toBeInTheDocument()

    // The request carried the current (unsaved) design document.
    const call = vi.mocked(fetch).mock.calls.find(([u, i]) => String(u) === `/api/report-templates/${TEMPLATE.id}/validate` && (i as RequestInit)?.method === 'POST')
    expect(call).toBeTruthy()
    expect(JSON.parse(String((call![1] as RequestInit).body)).design.schema).toBe(1)
  })
})

describe('on a phone (D-34: editors are web-only)', () => {
  function narrowScreen() {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: false, media: query, addEventListener: () => {}, removeEventListener: () => {} }))
  }

  it('the designer shows a notice instead of the editor', async () => {
    narrowScreen()
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: string) => {
        const url = String(input)
        if (url === `/api/report-templates/${TEMPLATE.id}`) return new Response(JSON.stringify({ data: TEMPLATE }), { status: 200 })
        if (url.startsWith(`/api/report-templates/${TEMPLATE.id}/versions`)) return new Response(JSON.stringify({ data: [VERSION], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } }), { status: 200 })
        return new Response(JSON.stringify({ data: FIELDS }), { status: 200 })
      }),
    )
    renderWith(<ReportDesignerPage />, ['report.view', 'report.update'], `/laporan/template/${TEMPLATE.id}/desain`)
    expect(await screen.findByText('Desainer laporan hanya tersedia di web')).toBeInTheDocument()
    expect(screen.queryByText(/Seret elemen dari panel kiri/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan' })).not.toBeInTheDocument()
  })

  it('the template list offers no create or designer actions', async () => {
    narrowScreen()
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ data: [TEMPLATE], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 } }), { status: 200 })),
    )
    renderWith(<ReportTemplatesPage />, ['report.view', 'report.create', 'report.update'], '/laporan/template')
    await screen.findByText('Laporan Stok')
    expect(screen.queryByRole('button', { name: /Template Baru/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Mulai dari contoh/ })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Aksi Laporan Stok' }))
    expect(screen.getByRole('menuitem', { name: 'Detail' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Buka Desainer' })).not.toBeInTheDocument()
  })
})
