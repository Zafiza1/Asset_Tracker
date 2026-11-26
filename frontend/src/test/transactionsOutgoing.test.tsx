import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Asset } from '../features/assets/api'
import { allowedTypes, daysLate, ineligibleReason, type AssetTransaction } from '../features/transactions/api'
import { TransactionChanges } from '../features/transactions/TransactionChanges'
import { TransactionFormModal } from '../features/transactions/TransactionFormModal'
import { TransactionsPage } from '../features/transactions/TransactionsPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const outgoing = (t: Partial<AssetTransaction> = {}) =>
  ({
    id: 't1',
    transaction_number: 'OUT-2026-000039',
    type: 'outgoing',
    type_label: 'Transaksi keluar',
    transaction_date: '2026-11-03',
    asset_id: 'a1',
    asset: { id: 'a1', name: 'Proyektor Epson' },
    from_status: 'in_use',
    to_status: 'off_site',
    borrower_name: null,
    due_date: '2026-11-10',
    notes: null,
    outgoing_nature: 'temporary',
    outgoing_nature_label: 'Sementara',
    reason_code: 'lent_external',
    reason_label: 'Dipinjamkan ke pihak luar',
    counterparty_name: 'Dinas Pendidikan Kota',
    counterparty_org: null,
    counterparty_contact: null,
    document_number: 'SJ/2026/1102',
    return_status: 'Terlambat',
    days_out: 14,
    created_at: '2026-11-03T00:00:00Z',
    ...t,
  }) as AssetTransaction

const laptop = {
  id: '01900000-0000-7000-8000-0000000000a1',
  name: 'Laptop',
  status: 'in_use',
  location_id: 'l1',
  location: { id: 'l1', code: 'GDG', name: 'Gudang' },
  is_archived: false,
} as unknown as Asset

function setup(permissions: string[], respond: (url: string, init?: RequestInit) => unknown = () => ({ data: [] })) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => new Response(JSON.stringify(respond(url, init)), { status: init?.method === 'POST' ? 201 : 200 }))
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  const wrap = (ui: ReactNode) =>
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={['/transaksi']}>{ui}</MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  return { fetchMock, wrap }
}

describe('outgoing and incoming rules', () => {
  it('lets active assets go out and only off-site assets come back', () => {
    expect(allowedTypes('in_use')).toContain('outgoing')
    expect(allowedTypes('available')).toContain('outgoing')
    expect(allowedTypes('under_maintenance')).not.toContain('outgoing')
    expect(allowedTypes('off_site')).toEqual(['incoming', 'note'])
    expect(ineligibleReason({ status: 'off_site', location_id: null }, 'outgoing')).toBe('Sudah di luar business unit')
    expect(ineligibleReason({ status: 'in_use', location_id: null }, 'incoming')).toBe('Tidak sedang di luar business unit')
  })

  it('counts the days past the planned return date', () => {
    expect(daysLate(outgoing(), '2026-11-17')).toBe(7)
    expect(daysLate(outgoing({ return_status: 'Belum kembali' }), '2026-11-17')).toBe(0)
  })
})

describe('TransactionChanges for outgoing rows', () => {
  it('shows nature, reason, party, return date, document and lateness', () => {
    vi.useFakeTimers({ now: new Date('2026-11-17T08:00:00'), toFake: ['Date'] })
    render(<TransactionChanges trx={outgoing()} />)
    expect(screen.getByText('Sementara · Dipinjamkan ke pihak luar')).toBeInTheDocument()
    expect(screen.getByText(/Dinas Pendidikan Kota/)).toBeInTheDocument()
    expect(screen.getByText('SJ/2026/1102')).toBeInTheDocument()
    expect(screen.getByText('Terlambat 7 hari')).toBeInTheDocument()
    vi.useRealTimers()
  })

  it('marks a returned row with the days it spent outside', () => {
    render(<TransactionChanges trx={outgoing({ return_status: 'Sudah kembali', days_out: 9 })} />)
    expect(screen.getByText('Sudah kembali · 9 hari di luar')).toBeInTheDocument()
  })
})

describe('TransactionFormModal outgoing', () => {
  it('sends a temporary outgoing transaction with its party and return date', async () => {
    const { fetchMock, wrap } = setup(['transaction.create', 'asset.outgoing'], (_url, init) =>
      init?.method === 'POST' ? { data: outgoing() } : { data: [] },
    )
    const onClose = vi.fn()
    wrap(<TransactionFormModal asset={laptop} initialType="outgoing" onClose={onClose} />)

    // Permanent outgoing disposes the asset: not offered without asset.dispose.
    expect(screen.getByText(/Permanen \(perlu izin/)).toBeDisabled()

    fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'external_repair' } })
    fireEvent.change(screen.getByLabelText(/Nama pihak luar/), { target: { value: 'CV Sinar Servis' } })
    fireEvent.change(screen.getByLabelText(/Rencana tanggal kembali/), { target: { value: '2099-01-10' } })
    fireEvent.click(screen.getByText('Catat keluar').closest('button')!)

    await waitFor(() => expect(onClose).toHaveBeenCalled())
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!
    expect(JSON.parse(post[1]!.body as string)).toMatchObject({
      asset_ids: [laptop.id],
      type: 'outgoing',
      outgoing_nature: 'temporary',
      reason_code: 'external_repair',
      counterparty_name: 'CV Sinar Servis',
      due_date: '2099-01-10',
    })
  })

  it('drops the return date for a permanent outgoing transaction', () => {
    const { wrap } = setup(['transaction.create', 'asset.outgoing', 'asset.dispose'])
    wrap(<TransactionFormModal asset={laptop} initialType="outgoing" onClose={vi.fn()} />)
    expect(screen.getByLabelText(/Rencana tanggal kembali/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/Sifat/), { target: { value: 'permanent' } })
    expect(screen.queryByLabelText(/Rencana tanggal kembali/)).not.toBeInTheDocument()
    expect(within(screen.getByLabelText(/Alasan/)).getByText('Dihibahkan')).toBeInTheDocument()
  })

  it('does not record the transaction when the asset search or its filters are submitted', async () => {
    const { fetchMock, wrap } = setup(['transaction.create', 'asset.view', 'asset.outgoing'], (url, init) => {
      if (init?.method === 'POST') return { data: outgoing() }
      return /^\/api\/assets(\?|$)/.test(url) ? { data: [laptop], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } } : { data: [] }
    })
    const onClose = vi.fn()
    wrap(<TransactionFormModal initialType="outgoing" onClose={onClose} />)
    const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')

    fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'external_repair' } })
    fireEvent.change(screen.getByLabelText(/Nama pihak luar/), { target: { value: 'CV Sinar Servis' } })
    fireEvent.change(screen.getByLabelText(/Rencana tanggal kembali/), { target: { value: '2099-01-10' } })
    fireEvent.click(await screen.findByLabelText('Pilih Laptop'))

    // Searching and filtering for assets are forms of their own, never part of the transaction form.
    const search = screen.getByLabelText('Cari aset')
    fireEvent.change(search, { target: { value: 'proyektor' } })
    fireEvent.submit(search.closest('form')!)
    fireEvent.click(screen.getByText('Filter lanjutan').closest('button')!)
    fireEvent.submit(screen.getByText('Terapkan filter').closest('form')!)
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(posts()).toHaveLength(0)
    expect(onClose).not.toHaveBeenCalled()
    expect(document.querySelector('form form')).toBeNull()

    // The save button still records it, once.
    fireEvent.click(screen.getByText(/^Catat keluar/).closest('button')!)
    await waitFor(() => expect(onClose).toHaveBeenCalled())
    expect(posts()).toHaveLength(1)
  })
})

describe('TransactionsPage tabs', () => {
  it('says what an empty tab holds instead of "no transactions at all"', async () => {
    const { wrap } = setup(['transaction.view'], (url) =>
      url.includes('/transactions/counts')
        ? { data: { all: 5, outgoing: 1, incoming: 1, not_returned: 0 } }
        : { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } },
    )
    wrap(<TransactionsPage />)
    fireEvent.click(await screen.findByRole('tab', { name: /Belum kembali/ }))
    expect(await screen.findByText('Tidak ada aset yang belum kembali')).toBeInTheDocument()
    expect(screen.queryByText('Belum ada transaksi')).not.toBeInTheDocument()
  })

  it('shows counts per tab and filters not-returned rows', async () => {
    const { fetchMock, wrap } = setup(['transaction.view'], (url) =>
      url.includes('/transactions/counts')
        ? { data: { all: 120, outgoing: 42, incoming: 31, not_returned: 11 } }
        : { data: [outgoing()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } },
    )
    wrap(<TransactionsPage />)

    const tab = await screen.findByRole('tab', { name: /Belum kembali/ })
    await waitFor(() => expect(tab).toHaveTextContent('11'))
    expect(screen.getByRole('tab', { name: /Keluar/ })).toHaveTextContent('42')
    expect(await screen.findByText('Keluar', { selector: 'span' })).toBeInTheDocument()

    fireEvent.click(tab)
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => /\/transactions\?.*not_returned=1/.test(String(url)))).toBe(true))
    expect(tab).toHaveAttribute('aria-selected', 'true')
  })
})
