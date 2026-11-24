import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ApprovalRequest } from '../features/approvals/api'
import { NotificationsPage } from '../features/approvals/NotificationsPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const request: ApprovalRequest = {
  id: 'a1',
  category: 'asset',
  category_label: 'Aset',
  action: 'asset.update',
  action_label: 'Perubahan data aset',
  summary: 'AST-1 · Laptop',
  status: 'pending',
  requester: { id: 'u2', name: 'Andi' },
  decider: null,
  decided_at: null,
  decision_note: null,
  created_at: '2026-11-10T08:00:00Z',
}

const detail: ApprovalRequest = {
  ...request,
  asset_id: 'asset-1',
  impact: { text: 'Nilai pada data yang ada diganti.', destructive: false },
  details: [
    { label: 'Nama', value: 'Laptop Baru', current: 'Laptop' },
    { label: 'Kategori', value: 'IT (IT)', current: 'IT (IT)' },
  ],
  history: [{ action: 'approval.submitted', label: 'Diajukan', actor: 'Andi', note: null, at: '2026-11-10T08:00:00Z' }],
}

// A transaction step waiting for the user (a flow approval), listed with the requests under "Menunggu".
const run = {
  id: 'r1', transaction_number: 'PJK-2026-000003', state: 'running', state_label: 'Berjalan',
  type_definition: { id: 't1', code: 'PJK', name: 'Peminjaman ke karyawan' }, current_step: { id: 's1', type: 'approval', label: 'Approval aset bernilai tinggi' },
  assets: [], created_by: { id: 'u3', name: 'Rina' }, created_at: '2026-11-23T02:00:00Z',
  open_task: { id: 'k1', node_id: 's1', kind: 'approval', due_date: null, can_act: true },
}

function renderPage({ rows = [request], counts = { pending: 1, approved: 4, rejected: 2, cancelled: 0, all: 7 }, url = '/notifikasi', approver = true, tasks = false } = {}) {
  const posts: { url: string; body: unknown }[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input)
      if (path.startsWith('/api/transaction-runs')) {
        return new Response(JSON.stringify({ data: tasks ? [run] : [], meta: { current_page: 1, last_page: 1, per_page: 50, total: tasks ? 1 : 0 } }), { status: 200 })
      }
      if (init?.method === 'POST') posts.push({ url: path, body: init.body ? JSON.parse(String(init.body)) : null })
      const data = path.includes('/approvals/counts') ? counts : path.includes('/approvals/a1') ? detail : null
      const body = data ? { data } : { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } }
      return new Response(JSON.stringify(body), { status: 200 })
    }),
  )
  const auth: AuthState = {
    profile: { user: { id: 'u1' }, permissions: approver ? ['approval.manage'] : [] } as unknown as Profile,
    isLoading: false,
    can: (p) => (approver && p === 'approval.manage') || (tasks && p === 'transaction.view'),
    login: vi.fn(),
    signUp: vi.fn(),
    logout: vi.fn(),
    setProfile: vi.fn(),
    switchOrganization: vi.fn(),
    createOrganization: vi.fn(),
  }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={[url]}>
          <NotificationsPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return posts
}

describe('Persetujuan page', () => {
  it('is called Persetujuan and shows the number of requests on every tab', async () => {
    renderPage()
    expect(screen.getByRole('heading', { name: 'Persetujuan' })).toBeInTheDocument()
    expect(await screen.findByRole('tab', { name: /Disetujui\s*4/ })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Menunggu\s*1/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: /Semua\s*7/ })).toBeInTheDocument()
    expect(screen.getByRole('search')).toBeInTheDocument()
  })

  it('tells the approver per tab why the list is empty, and when a search found nothing', async () => {
    renderPage({ rows: [], url: '/notifikasi?status=rejected' })
    expect(await screen.findByText('Belum ada pengajuan yang ditolak')).toBeInTheDocument()
  })

  it('offers to clear the search when it found nothing', async () => {
    renderPage({ rows: [], url: '/notifikasi?search=xyz' })
    expect(await screen.findByText('Tidak ada pengajuan yang cocok')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Hapus pencarian dan filter' })).toBeInTheDocument()
  })

  it('shows impact, current versus submitted values and history, and requires a reason to reject', async () => {
    const posts = renderPage()
    fireEvent.click(await screen.findByText('Perubahan data aset'))
    // jsdom does not open <dialog>, so it stays out of the accessibility tree.
    const dialog = await screen.findByRole('dialog', { hidden: true })
    expect(await within(dialog).findByText('Dampak jika disetujui')).toBeInTheDocument()
    expect(within(dialog).getByText('Laptop Baru').closest('tr')).toHaveTextContent('Nama (berubah)LaptopLaptop Baru')
    expect(within(dialog).getByText('Riwayat persetujuan')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: /Lihat aset/, hidden: true })).toHaveAttribute('href', '/aset/asset-1')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak…', hidden: true }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak pengajuan', hidden: true }))
    expect(await within(dialog).findByText('Alasan penolakan wajib diisi.')).toBeInTheDocument()
    expect(posts).toHaveLength(0)

    fireEvent.change(within(dialog).getByLabelText(/Alasan penolakan/), { target: { value: 'Nama tidak sesuai label' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak pengajuan', hidden: true }))
    await vi.waitFor(() => expect(posts.find((p) => p.url.endsWith('/approvals/a1/reject'))?.body).toEqual({ note: 'Nama tidak sesuai label' }))
  })
})

describe('transaction steps in the same list', () => {
  it('lists the steps waiting for the approver under Menunggu, as kind Transaksi, opening the transaction', async () => {
    renderPage({ tasks: true })
    expect(await screen.findByRole('link', { name: /PJK-2026-000003/ })).toHaveAttribute('href', '/transaksi/berjalan/r1')
    expect(screen.getByText('Peminjaman ke karyawan · Approval aset bernilai tinggi')).toBeInTheDocument()
    expect(screen.getByText('Perubahan data aset')).toBeInTheDocument()
    // One tab row, one list: the count holds both.
    expect(await screen.findByRole('tab', { name: /Menunggu\s*2/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getAllByRole('tablist')).toHaveLength(1)
  })

  it('leaves the steps out of the other tabs and of the other kinds', async () => {
    renderPage({ tasks: true, url: '/notifikasi?category=asset' })
    expect(await screen.findByText('Perubahan data aset')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /PJK-2026-000003/ })).not.toBeInTheDocument()
  })
})

describe('Pengajuan Saya page (users without approval.manage)', () => {
  it('is called Pengajuan Saya, opens on all submissions and offers no decision', async () => {
    renderPage({ approver: false })
    expect(screen.getByRole('heading', { name: 'Pengajuan Saya' })).toBeInTheDocument()
    expect(await screen.findByRole('tab', { name: /Semua\s*7/ })).toHaveAttribute('aria-selected', 'true')
    fireEvent.click(await screen.findByText('Perubahan data aset'))
    const dialog = await screen.findByRole('dialog', { hidden: true })
    expect(await within(dialog).findByText('Rincian pengajuan')).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Setujui', hidden: true })).toBeNull()
  })
})

describe('deciding a request', () => {
  it('asks once more before approving, then approves and can still be backed out of', async () => {
    const posts = renderPage()
    fireEvent.click(await screen.findByText('Perubahan data aset'))
    const dialog = await screen.findByRole('dialog', { hidden: true })

    fireEvent.click(await within(dialog).findByRole('button', { name: 'Setujui', hidden: true }))
    expect(within(dialog).getByText('Setujui pengajuan ini?')).toBeInTheDocument()
    expect(posts).toHaveLength(0)

    // "Kembali" returns to the decision without sending anything.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kembali', hidden: true }))
    expect(within(dialog).queryByText('Setujui pengajuan ini?')).not.toBeInTheDocument()
    expect(posts).toHaveLength(0)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Setujui', hidden: true }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ya, setujui', hidden: true }))
    await vi.waitFor(() => expect(posts.find((p) => p.url.endsWith('/approvals/a1/approve'))?.body).toEqual({ note: null }))
  })
})
