import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DashboardPage, type Dashboard } from '../features/dashboard/DashboardPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('DashboardPage widget picker', () => {
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
    age: { buckets: [], unknown: 1, average_years: null },
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

  it('hides an unchecked analysis and remembers it for the same user', async () => {
    const { unmount } = renderPage()
    expect(await screen.findByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Tampilan/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Per lokasi' }))
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    unmount()

    renderPage()
    expect(await screen.findByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Per lokasi' })).not.toBeInTheDocument()
  })

  it('keeps each user’s choice separate', async () => {
    localStorage.setItem('dashboard:hidden:u1', JSON.stringify(['location']))
    renderPage('u2')
    expect(await screen.findByRole('heading', { name: 'Per lokasi' })).toBeInTheDocument()
  })

  it('offers to show everything again when all analyses are hidden', async () => {
    localStorage.setItem('dashboard:hidden:u1', JSON.stringify(['summary', 'status', 'utilization', 'data_quality', 'trend', 'age', 'category', 'location', 'transaction_types', 'activity']))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Tampilkan semua' }))
    expect(screen.getByRole('heading', { name: 'Status aset' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Ringkasan' })).toBeInTheDocument()
  })
})
