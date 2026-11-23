import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ago, arrangeCards, type HomeCard, type HomeCardsResponse } from '../features/home/api'
import { HomePage } from '../features/home/HomePage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'
import { moveWithKeyboard, stackSections } from './sortableHelpers'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const cards: HomeCard[] = [
  {
    key: 'approvals_to_decide',
    count: 1,
    items: [{ id: 'ap1', summary: 'Pemindahan 3 aset ke Gudang Cirebon', action: 'transaction.create', requested_by: 'Andi', created_at: '2026-11-17T08:00:00Z' }],
  },
  { key: 'transaction_tasks', count: 0, overdue: 0, items: [] },
  {
    key: 'my_requests',
    days: 30,
    counts: { pending: 0, approved: 2, rejected: 1, cancelled: 0 },
    items: [{ id: 'r1', summary: 'Ubah lokasi Laptop Dell', status: 'rejected', decision_note: 'Lokasi tujuan salah', decided_by: 'Dewi', created_at: '2026-11-18T08:00:00Z' }],
  },
  {
    key: 'overdue_outgoing',
    count: 1,
    items: [{ transaction_number: 'TRX-1', asset: { id: 'a1', name: 'Proyektor Epson' }, counterparty_name: 'Dinas Pendidikan', due_date: '2026-11-11', days_late: 8 }],
  },
]

function setup(response: Partial<HomeCardsResponse['meta']['preferences']> = {}, wide = true, extra: HomeCard[] = []) {
  const payload: HomeCardsResponse = { data: [...cards, ...extra], meta: { refresh_seconds: 60, preferences: { order: [], hidden: [], ...response } } }
  const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
    new Response(JSON.stringify(init?.method === 'PUT' ? { data: JSON.parse(String(init.body)) } : payload), { status: 200 }),
  )
  vi.stubGlobal('fetch', fetchMock)
  vi.stubGlobal('matchMedia', (query: string) => ({ matches: wide, media: query, addEventListener: () => {}, removeEventListener: () => {} }))
  document.cookie = 'XSRF-TOKEN=test'
  const auth: AuthState = {
    profile: { user: { id: 'u1', name: 'Rina' }, organization: { name: 'PT Surya' }, data_scope: { organization_wide: true, department_ids: [], location_ids: [] }, permissions: [] } as unknown as Profile,
    isLoading: false, can: () => true, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn(),
  }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>
          <HomePage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

const cardTitles = () => screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)

describe('Beranda', () => {
  it('lists internal loans past their return date, most overdue first', async () => {
    setup({}, true, [{ key: 'overdue_loans', count: 2, items: [
      { asset: { id: 'a7', name: 'Laptop Dell' }, borrower_name: 'Budi', due_date: '2026-11-14', days_late: 6 },
      { asset: { id: 'a8', name: 'Kamera Canon' }, borrower_name: null, due_date: '2026-11-19', days_late: 1 },
    ] }])
    expect(await screen.findByRole('heading', { level: 2, name: 'Peminjaman lewat batas' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Laptop Dell/ })).toHaveAttribute('href', '/aset/a7')
    expect(screen.getByText('dipinjam Budi')).toBeInTheDocument()
    expect(screen.getByText('6 hari')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Lihat semua yang lewat batas/ })).toHaveAttribute('href', '/aset?overdue=1')
  })

  it('shows the cards the server sent, with a calm message when nothing waits', async () => {
    setup()
    expect(await screen.findByText('Pemindahan 3 aset ke Gudang Cirebon')).toBeInTheDocument()
    expect(cardTitles()).toEqual(['Menunggu keputusan saya', 'Langkah transaksi menunggu saya', 'Pengajuan saya', 'Keluar sementara lewat batas'])
    expect(screen.getByText('Tidak ada langkah yang menunggu Anda.')).toBeInTheDocument()
    expect(screen.getByText(/Lokasi tujuan salah/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Proyektor Epson/ })).toHaveAttribute('href', '/aset/a1')
    expect(screen.getByRole('link', { name: /Lihat aset belum kembali/ })).toHaveAttribute('href', '/transaksi/riwayat?not_returned=1')
  })

  it('follows the saved order and leaves hidden cards out', async () => {
    setup({ order: ['overdue_outgoing', 'my_requests'], hidden: ['transaction_tasks'] })
    await screen.findByText('Pemindahan 3 aset ke Gudang Cirebon')
    expect(cardTitles()).toEqual(['Keluar sementara lewat batas', 'Pengajuan saya', 'Menunggu keputusan saya'])
  })

  it('lets the member reorder, hide and save their cards', async () => {
    const fetchMock = setup()
    fireEvent.click(await screen.findByRole('button', { name: /Atur kartu/ }))
    stackSections()
    // Dragged one place up (with the keyboard, as jsdom has no pointer layout).
    await moveWithKeyboard('Pengajuan saya', 'ArrowUp')
    fireEvent.click(screen.getByRole('button', { name: 'Sembunyikan Menunggu keputusan saya' }))
    const hiddenCard = screen.getByRole('region', { name: 'Menunggu keputusan saya' })
    expect(within(hiddenCard).getByText('Disembunyikan')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Simpan susunan' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Simpan susunan' })).not.toBeInTheDocument())
    const put = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')
    expect(put?.[0]).toBe('/api/home/preferences')
    expect(JSON.parse(String(put?.[1]?.body))).toEqual({
      order: ['approvals_to_decide', 'my_requests', 'transaction_tasks', 'overdue_outgoing'],
      hidden: ['approvals_to_decide'],
    })
    expect(cardTitles()).toEqual(['Pengajuan saya', 'Langkah transaksi menunggu saya', 'Keluar sementara lewat batas'])
  })

  it('offers no card arranging on a phone (D-34)', async () => {
    setup({}, false)
    await screen.findByText('Pemindahan 3 aset ke Gudang Cirebon')
    expect(screen.queryByRole('button', { name: /Atur kartu/ })).not.toBeInTheDocument()
  })
})

describe('home helpers', () => {
  it('puts placed cards first and new ones after, in server order', () => {
    const keys = arrangeCards(cards, ['overdue_outgoing']).map((c) => c.key)
    expect(keys).toEqual(['overdue_outgoing', 'approvals_to_decide', 'transaction_tasks', 'my_requests'])
  })

  it('says how long ago', () => {
    const now = Date.parse('2026-11-19T10:00:00Z')
    expect(ago('2026-11-19T09:59:30Z', now)).toBe('baru saja')
    expect(ago('2026-11-19T09:15:00Z', now)).toBe('45 menit')
    expect(ago('2026-11-19T05:00:00Z', now)).toBe('5 jam')
    expect(ago('2026-11-17T09:00:00Z', now)).toBe('2 hari')
  })
})
