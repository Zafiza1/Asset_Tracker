import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import type { Asset } from '../features/assets/api'
import { AssetReportPage, type AssetReportSummary } from '../features/reports/AssetReportPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('AssetReportPage', () => {
  const asset = {
    id: '01900000-0000-7000-8000-000000000001',
    asset_number: 'AST-2026-000001',
    serial_number: 'SN-1',
    name: 'Laptop Dell',
    category_id: 'c1',
    category: { id: 'c1', code: 'IT', name: 'Perangkat IT' },
    location_id: 'l1',
    location: { id: 'l1', code: 'GD', name: 'Gudang' },
    status: 'available',
    acquisition_date: '2024-01-15',
    acquisition_cost: '15000000.00',
    is_archived: false,
  } as Asset
  const summary: AssetReportSummary = {
    total: 1,
    archived: 0,
    total_value: '15000000.00',
    by_status: [
      { status: 'available', label: 'Tersedia', count: 1 },
      { status: 'in_use', label: 'Digunakan', count: 0 },
    ],
    by_category: [{ id: 'c1', name: 'Perangkat IT', count: 1, value: '15000000.00' }],
  }

  function renderPage(permissions: string[], url = '/laporan') {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: string) => {
        if (input.startsWith('/api/reports/assets/export')) return new Response(new Blob(['xlsx']), { status: 200 })
        if (input.startsWith('/api/reports/assets')) {
          return new Response(JSON.stringify({ data: [asset], summary, meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } }), { status: 200 })
        }
        return new Response(JSON.stringify({ data: [] }), { status: 200 })
      }),
    )
    // jsdom has no object URLs; downloadFile needs them to save the file.
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    const can = (p: string) => permissions.includes(p)
    const auth: AuthState = { profile: { permissions } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
    return render(
      <QueryClientProvider client={new QueryClient()}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={[url]}>
            <AssetReportPage />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  }

  it('shows the assets with their totals', async () => {
    renderPage(['report.view'])
    expect(await screen.findByText('Laptop Dell')).toBeInTheDocument()
    expect(screen.getByText('Jumlah aset').nextSibling).toHaveTextContent('1')
    expect(screen.getByText('Tersedia: 1')).toBeInTheDocument()
    // Without report.export the export actions are hidden.
    expect(screen.queryByRole('button', { name: /Export/ })).not.toBeInTheDocument()
  })

  it('exports with the filters shown on screen', async () => {
    renderPage(['report.view', 'report.export'], '/laporan?status=available&page=2')
    await screen.findByText('Laptop Dell')
    fireEvent.click(screen.getByRole('button', { name: /Export Excel/ }))

    await waitFor(() => expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).startsWith('/api/reports/assets/export'))).toBe(true))
    const exportUrl = String(vi.mocked(fetch).mock.calls.find(([u]) => String(u).startsWith('/api/reports/assets/export'))![0])
    const query = new URL(exportUrl, 'http://x').searchParams
    expect(query.get('format')).toBe('xlsx')
    expect(query.get('status')).toBe('available')
    expect(query.has('page')).toBe(false)
  })

  it('is a permission-guarded menu item', () => {
    const item = tenantNavigation.flatMap((s) => s.items).find((i) => i.to === '/laporan')
    expect(item?.permission).toBe('report.view')
  })
})
