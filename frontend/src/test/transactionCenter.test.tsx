import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { TransactionCenterPage } from '../features/transactions/TransactionCenterPage'
import type { CenterCard, TransactionTypeDefinition } from '../features/transactionTypes/api'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const card = (id: string, name: string, extra: Partial<CenterCard> = {}): CenterCard => ({
  id, code: 'X', name, description: `Kegunaan ${name}.`, icon: null, system_type: null, core_type: null, multi_asset: true, field_count: 1, categories: [],
  group: 'perpindahan', flow_version: 1, needs_approval: true, summary: 'Transaksi ini perlu disetujui Supervisor yang membawahi aset sebelum aset dipindah.', ...extra,
})

const mutasi: TransactionTypeDefinition = {
  id: 't-mut', code: 'MUT', name: 'Mutasi antar lokasi', description: null, icon: null, status: 'active', multi_asset: true, locks_assets: true, categories: [],
  fields: [{ key: 'penerima', label: 'Penerima', data_type: 'text', settings: { help: 'Nama orang yang menerima aset di lokasi tujuan.' }, is_required: true }],
  created_at: '2026-11-23T00:00:00Z', updated_at: '2026-11-23T00:00:00Z',
}

function setup(cards: CenterCard[], path = '/transaksi') {
  const fetchMock = vi.fn(async (url: string) => {
    let body: unknown = { data: [] }
    if (url.startsWith('/api/transaction-center')) body = { data: cards, meta: { groups: { perpindahan: 'Perpindahan', peminjaman: 'Peminjaman', perawatan: 'Perawatan', penghapusan: 'Penghapusan', lainnya: 'Lainnya' }, tasks: 2, running: 1 } }
    else if (url.startsWith('/api/transaction-runs')) body = { data: [{ id: 'r1', transaction_number: 'MUT-2026-000044', state: 'running', state_label: 'Berjalan', type_definition: { id: 't-mut', code: 'MUT', name: 'Mutasi antar lokasi' }, current_step: { id: 'setuju', type: 'approval', label: 'Persetujuan Supervisor lokasi' }, assets: [{ id: 'a1', name: 'Proyektor Epson', locked: true }], created_by: { id: 'u1', name: 'Rina' }, created_at: '2026-11-23T02:00:00Z', is_mine: false, transaction_date: '2026-11-23', notes: null, ended_at: null, open_task: { id: 'k1', node_id: 'setuju', kind: 'approval', assignee_type: 'location_supervisor', assignee_id: null, assignee_name: null, due_date: '2026-11-24', required_approvals: 1, approvals: 0, can_act: true } }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 50 } }
    else if (url.includes('/flow/describe')) body = { data: { summary: 'Perlu disetujui Supervisor.' } }
    else if (url.includes('/flow')) body = { data: { active: { id: 'v1', version_number: 1, status: 'published', graph: { nodes: [], edges: [] } }, draft: null, versions: [] } }
    else if (url.startsWith('/api/transaction-types')) body = { data: [mutasi] }
    return new Response(JSON.stringify(body), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const permissions = ['transaction.view', 'transaction.create', 'asset.view', 'asset.transfer']
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can: (p: string) => permissions.includes(p), login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path="/transaksi" element={<TransactionCenterPage />} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

describe('Transaction Center', () => {
  it('groups the transactions the user may record, told in short', async () => {
    setup([
      card('t-mut', 'Mutasi antar lokasi'),
      card('sys-loan', 'Peminjaman', { system_type: 'loan', group: 'peminjaman', needs_approval: false, summary: 'Langsung tercatat.', flow_version: null }),
    ])
    const moving = (await screen.findByRole('heading', { name: 'Perpindahan' })).closest('section')!
    expect(within(moving).getByText('Mutasi antar lokasi')).toBeInTheDocument()
    expect(within(moving).getByText('Perlu persetujuan')).toBeInTheDocument()
    expect(within(moving).getByText(/sebelum aset dipindah/)).toBeInTheDocument()
    const lending = screen.getByRole('heading', { name: 'Peminjaman' }).closest('section')!
    expect(within(lending).getByText('Langsung tercatat')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Perlu tindakan saya/ })).toHaveTextContent('2')
    expect(screen.getByRole('link', { name: /Riwayat transaksi/ })).toHaveAttribute('href', '/transaksi/riwayat')
  })

  it('opens the form with the chosen organization type and its field hints', async () => {
    setup([card('t-mut', 'Mutasi antar lokasi')])
    fireEvent.click((await screen.findByText('Mutasi antar lokasi')).closest('button')!)
    expect(await screen.findByText('Nama orang yang menerima aset di lokasi tujuan.')).toBeInTheDocument()
    expect(screen.getByLabelText(/Jenis transaksi/)).toHaveValue('custom:t-mut')
    expect(await screen.findByText(/Perlu disetujui Supervisor\./)).toBeInTheDocument()
  })

  it('lists the steps waiting for the user', async () => {
    const fetchMock = setup([card('t-mut', 'Mutasi antar lokasi')], '/transaksi?tab=tugas')
    expect(await screen.findByText('MUT-2026-000044')).toBeInTheDocument()
    expect(screen.getByText(/Setujui: Persetujuan Supervisor lokasi · batas/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /MUT-2026-000044/ })).toHaveAttribute('href', '/transaksi/berjalan/r1')
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('assigned_to_me=1'))).toBe(true)
  })

  it('tells a user without rights to record why there is nothing', async () => {
    setup([])
    expect(await screen.findByText('Belum ada transaksi yang dapat Anda lakukan')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Catat transaksi/ })).not.toBeInTheDocument()
  })
})
