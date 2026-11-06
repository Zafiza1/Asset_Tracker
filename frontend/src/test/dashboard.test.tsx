import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, renderHook, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DashboardPage, type Dashboard } from '../features/dashboard/DashboardPage'
import { normalizeLayout, useDashboardLayout } from '../features/dashboard/layout'
import { effectiveSize } from '../features/dashboard/widgetSize'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('DashboardPage widgets', () => {
  const dashboard: Dashboard = {
    generated_at: '2026-11-02T08:00:00Z',
    summary: { total: 1, archived: 0, total_value: '1000.00', added_this_month: 1, currency: 'IDR' },
    by_status: [{ status: 'available', label: 'Tersedia', count: 1 }],
    by_category: [{ id: 'c1', name: 'Perangkat IT', count: 1 }],
    by_location: [{ id: 'l1', name: 'Gudang', count: 1 }],
    transactions: { days: 30, total: 0, by_type: [], recent: [] },
    trend: [],
    attention: { counts: { overdue_loan: 0, under_maintenance: 0, no_location: 0 }, items: [] },
    utilization: { in_service: 0, available: 1, operable: 1, rate: 0 },
    loans: { active: 0, overdue: 0, due_soon: 0, no_due_date: 0, due_soon_days: 7 },
    age: { buckets: [], unknown: 1, average_years: null, by_category: [], oldest: [] },
    value_by_category: [],
    data_quality: { total: 1, fields: [], score: null },
    idle: { days: 90, count: 0, items: [] },
  }

  function renderPage(userId = 'u1') {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ data: dashboard }), { status: 200 })))
    const auth: AuthState = { profile: { user: { id: userId }, permissions: [] } as unknown as Profile, isLoading: false, can: () => true, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
    return render(
      <QueryClientProvider client={new QueryClient()}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter>
            <DashboardPage />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  }

  const headings = () => screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)

  it('hides a widget while arranging and remembers it for the same user', async () => {
    const { unmount } = renderPage()
    expect(await screen.findByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Atur tata letak/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Sembunyikan Per lokasi' }))
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Selesai/ }))
    unmount()

    renderPage()
    expect(await screen.findByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Atur tata letak/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Per lokasi' }))
    expect(screen.getByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()
  })

  it('hides and shows a widget from the Tampilan picker', async () => {
    renderPage()
    expect(await screen.findByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Tampilan/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Per lokasi' }))
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Per lokasi' }))
    expect(screen.getByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()
  })

  it('only shows arranging controls in edit mode', async () => {
    renderPage()
    await screen.findByRole('heading', { name: 'Per lokasi' })
    expect(screen.queryByRole('button', { name: 'Pindahkan Per lokasi' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Atur tata letak/ }))
    expect(screen.getByRole('button', { name: 'Pindahkan Per lokasi' })).toBeInTheDocument()
  })

  it('remembers a changed widget width', async () => {
    const { unmount } = renderPage()
    await screen.findByRole('heading', { name: 'Per lokasi' })
    fireEvent.click(screen.getByRole('button', { name: /Atur tata letak/ }))
    fireEvent.change(screen.getByRole('combobox', { name: 'Ukuran Per lokasi' }), { target: { value: 'full' } })
    unmount()

    renderPage()
    await screen.findByRole('heading', { name: 'Per lokasi' })
    fireEvent.click(screen.getByRole('button', { name: /Atur tata letak/ }))
    expect(screen.getByRole('combobox', { name: 'Ukuran Per lokasi' })).toHaveValue('full')
  })

  it('renders widgets in the saved order and keeps each user’s layout separate', async () => {
    localStorage.setItem('dashboard:layout:u1', JSON.stringify([{ key: 'location', size: 'half', hidden: false }]))
    const { unmount } = renderPage()
    await screen.findByRole('heading', { name: 'Per lokasi' })
    expect(headings()[0]).toBe('Per lokasi')
    expect(headings()).toContain('Status aset') // widgets missing from the saved layout are appended
    unmount()

    renderPage('u2')
    await screen.findByRole('heading', { name: 'Per lokasi' })
    expect(headings()[0]).toBe('Status aset')
  })

  it('carries over widgets hidden before layouts existed', async () => {
    localStorage.setItem('dashboard:hidden:u1', JSON.stringify(['summary', 'location']))
    renderPage()
    await screen.findByRole('heading', { name: 'Status aset' })
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()
    expect(screen.queryByText('Aset aktif')).not.toBeInTheDocument()
  })

  describe('age widget views', () => {
    const age: Dashboard['age'] = {
      buckets: [
        { key: 'lt1', label: '< 1 tahun', count: 2 },
        { key: '5_10', label: '5–10 tahun', count: 2 },
      ],
      unknown: 1,
      average_years: 3.5,
      by_category: [{ id: 'c1', name: 'Perangkat IT', total: 4, average_years: 3.5, buckets: { lt1: 2, '5_10': 2 } }],
      oldest: [{ asset: { id: 'a1', asset_number: 'A-1', name: 'Proyektor lama' }, location: null, acquisition_date: '2020-01-01', age_years: 6.8 }],
    }

    async function renderAgeAt(size: string) {
      dashboard.age = age
      localStorage.setItem('dashboard:layout:u1', JSON.stringify([{ key: 'age', size, hidden: false }]))
      renderPage()
      return within((await screen.findByRole('heading', { name: 'Umur aset' })).closest('section')!)
    }

    afterEach(() => {
      dashboard.age = { buckets: [], unknown: 1, average_years: null, by_category: [], oldest: [] }
    })

    it('draws only the columns at 1/3 width', async () => {
      const widget = await renderAgeAt('third')
      expect(widget.getByRole('img', { name: /Jumlah aset per kelompok umur/ })).toBeInTheDocument()
      expect(widget.queryByText('Rata-rata umur')).not.toBeInTheDocument()
    })

    it('leads with the average and band shares at 1/2 width', async () => {
      const widget = await renderAgeAt('half')
      expect(widget.getByText('Rata-rata umur')).toBeInTheDocument()
      expect(widget.getByText(/aset berumur 5 tahun ke atas/)).toBeInTheDocument()
      expect(widget.queryByRole('img', { name: /Jumlah aset per kelompok umur/ })).not.toBeInTheDocument()
    })

    it('breaks age down per category at 2/3 width', async () => {
      const widget = await renderAgeAt('two_thirds')
      expect(widget.getByRole('img', { name: 'Perangkat IT: < 1 tahun 2, 5–10 tahun 2' })).toBeInTheDocument()
      expect(widget.queryByText('Aset tertua')).not.toBeInTheDocument()
    })

    it('shows the grid and the oldest assets at full width', async () => {
      const widget = await renderAgeAt('full')
      expect(widget.getByRole('table')).toBeInTheDocument()
      expect(widget.getByRole('link', { name: 'Proyektor lama' })).toHaveAttribute('href', '/aset/a1')
      expect(widget.getByRole('img', { name: /Jumlah aset per kelompok umur/ })).toBeInTheDocument()
    })
  })

  it('offers to show everything again when all analyses are hidden', async () => {
    localStorage.setItem('dashboard:hidden:u1', JSON.stringify(['summary', 'status', 'utilization', 'data_quality', 'trend', 'age', 'category', 'location', 'transaction_types', 'activity']))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Tampilkan semua' }))
    expect(screen.getByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    expect(screen.getByText('Aset aktif')).toBeInTheDocument()
  })
})

describe('effectiveSize', () => {
  it('keeps the chosen view when it fits, and on wide monitors never upgrades it', () => {
    expect(effectiveSize('full', 1200)).toBe('full')
    expect(effectiveSize('third', 1200)).toBe('third')
  })

  it('steps down to the largest view that fits a narrow card', () => {
    expect(effectiveSize('full', 700)).toBe('two_thirds')
    expect(effectiveSize('full', 500)).toBe('half')
    expect(effectiveSize('full', 350)).toBe('third')
    expect(effectiveSize('half', 350)).toBe('third')
  })

  it('keeps the chosen view until the card has been measured', () => {
    expect(effectiveSize('full', null)).toBe('full')
    expect(effectiveSize('quarter', 200)).toBe('quarter')
  })
})

describe('dashboard layout', () => {
  it('moves a widget to another position', () => {
    const { result } = renderHook(() => useDashboardLayout('u1'))
    act(() => result.current.move('activity', 'stat_total'))
    expect(result.current.items.map((i) => i.key).slice(0, 2)).toEqual(['activity', 'stat_total'])
    act(() => result.current.reset())
    expect(result.current.items[0].key).toBe('stat_total')
  })

  it('drops unknown widgets and sizes a widget does not allow', () => {
    const items = normalizeLayout([{ key: 'gone' }, { key: 'stat_total', size: 'full' }, { key: 'stat_total', size: 'half' }])
    expect(items[0]).toEqual({ key: 'stat_total', size: 'quarter', hidden: false })
    expect(items.filter((i) => i.key === 'stat_total')).toHaveLength(1)
  })
})
