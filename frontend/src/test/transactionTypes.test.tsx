import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Asset } from '../features/assets/api'
import { TransactionFormModal } from '../features/transactions/TransactionFormModal'
import { laterFields, type FlowGraph, type TransactionRun, type TransactionTypeDefinition } from '../features/transactionTypes/api'
import { TransactionRunPage } from '../features/transactionTypes/TransactionRunPage'
import { TransactionTypeFormPage } from '../features/transactionTypes/TransactionTypeFormPage'
import { TransactionTypesPage } from '../features/transactionTypes/TransactionTypesPage'
import { keyFromLabel, toPayload } from '../features/transactionTypes/values'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const mutasi: TransactionTypeDefinition = {
  id: 't-mut',
  code: 'MUT',
  name: 'Mutasi Cabang',
  description: null,
  icon: null,
  status: 'active',
  multi_asset: true,
  locks_assets: true,
  closes_type: null,
  categories: [],
  fields: [
    { key: 'alasan', label: 'Alasan mutasi', data_type: 'select', settings: { options: [{ value: 'PROYEK', label: 'Kebutuhan proyek' }] }, is_required: true },
    { key: 'no_bast', label: 'No. BAST', data_type: 'text', settings: {}, is_required: true },
  ],
  transactions_count: 16,
  flow: { active_version: 3, draft_version: 4 },
  created_at: '2026-11-18T00:00:00Z',
  updated_at: '2026-11-18T00:00:00Z',
}

const graph: FlowGraph = {
  nodes: [
    { id: 'start', type: 'start', label: 'Mulai', position: { x: 0, y: 0 }, config: {} },
    { id: 'setuju', type: 'approval', label: 'Persetujuan Supervisor', position: { x: 200, y: 0 }, config: { approver: { type: 'location_supervisor' } } },
    { id: 'serah', type: 'input', label: 'Serah terima', position: { x: 400, y: 0 }, config: { fields: ['no_bast'], required: ['no_bast'] } },
    { id: 'ok', type: 'end', label: 'Selesai', position: { x: 600, y: 0 }, config: { result: 'completed' } },
    { id: 'tolak', type: 'end', label: 'Ditolak', position: { x: 600, y: 100 }, config: { result: 'rejected' } },
  ],
  edges: [
    { id: 'e1', source: 'start', target: 'setuju', outcome: null },
    { id: 'e2', source: 'setuju', target: 'serah', outcome: 'approved' },
    { id: 'e3', source: 'setuju', target: 'tolak', outcome: 'rejected' },
    { id: 'e4', source: 'serah', target: 'ok', outcome: null },
  ],
}

const laptop = { id: 'a1', name: 'Laptop Dell', status: 'available', location_id: 'l1', location: { id: 'l1', code: 'G', name: 'Gudang' }, is_archived: false } as unknown as Asset

function setup(permissions: string[], respond: (url: string, init?: RequestInit) => unknown, path = '/') {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => new Response(JSON.stringify(respond(url, init)), { status: init?.method === 'POST' ? 201 : 200 }))
  vi.stubGlobal('fetch', fetchMock)
  const can = (p: string) => permissions.includes(p)
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  const wrap = (ui: ReactNode, route = '*') =>
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={[path]}>
            <Routes>
              <Route path={route} element={ui} />
            </Routes>
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  return { fetchMock, wrap }
}

describe('field helpers', () => {
  it('derives a unique key from a label', () => {
    expect(keyFromLabel('Lokasi tujuan', [])).toBe('lokasi_tujuan')
    expect(keyFromLabel('No. BAST', ['no_bast'])).toBe('no_bast_2')
    expect(keyFromLabel('2 Foto', [])).toBe('f_2_foto')
    expect(keyFromLabel('Catatan Pengécekan', [])).toBe('catatan_pengecekan')
  })

  it('sends attachment ids and blanks as null', () => {
    const fields = [
      { key: 'foto', label: 'Foto', data_type: 'attachment' as const, settings: {}, is_required: false },
      { key: 'catatan', label: 'Catatan', data_type: 'text' as const, settings: {}, is_required: false },
    ]
    expect(toPayload(fields, { foto: { id: 'f1', name: 'a.jpg', size: 1, mime_type: 'image/jpeg' }, catatan: '  ' })).toEqual({ foto: 'f1', catatan: null })
  })

  it('knows which fields a later step asks for', () => {
    expect([...laterFields(graph)]).toEqual(['no_bast'])
    expect(laterFields(null).size).toBe(0)
  })
})

describe('Jenis Transaksi page', () => {
  it('lists types with their flow versions; only managers get "Jenis baru"', async () => {
    const { wrap } = setup(['transaction.view'], () => ({ data: [mutasi] }))
    wrap(<TransactionTypesPage />)
    expect(await screen.findByText('Mutasi Cabang')).toBeInTheDocument()
    expect(screen.getByText('Terbit v3')).toBeInTheDocument()
    expect(screen.getByText('Draft v4')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Jenis baru/ })).not.toBeInTheDocument()
  })

  it('lists the system types read-only, after the organization types, and filters by kind', async () => {
    const loan: TransactionTypeDefinition = { ...mutasi, id: 't-pjm', code: 'PJM', name: 'Peminjaman', system_type: 'loan', fields: [], transactions_count: 0, flow: {} }
    const { fetchMock, wrap } = setup(['transaction.view', 'transaction_type.manage'], () => ({ data: [loan, mutasi] }))
    wrap(<TransactionTypesPage />)

    expect(await screen.findByText('Peminjaman')).toBeInTheDocument()
    expect(String(fetchMock.mock.calls[0][0])).toContain('include_system=1')
    const links = screen.getAllByRole('link').map((l) => l.textContent).filter((n) => n === 'Mutasi Cabang' || n === 'Peminjaman')
    expect(links).toEqual(['Mutasi Cabang', 'Peminjaman'])
    expect(screen.getByText('Sistem', { ignore: 'option' })).toBeInTheDocument()
    expect(screen.getByText('PJM · aturan inti tetap')).toBeInTheDocument()
    expect(screen.getByText('Langsung tercatat')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Alur Mutasi Cabang' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Alur Peminjaman' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Filter jenis'), { target: { value: 'system' } })
    expect(screen.queryByText('Mutasi Cabang')).not.toBeInTheDocument()
    expect(screen.getByText('Peminjaman')).toBeInTheDocument()
  })

  it('tells an organization without its own types that the system types exist', async () => {
    const loan: TransactionTypeDefinition = { ...mutasi, id: 't-pjm', code: 'PJM', name: 'Peminjaman', system_type: 'loan', fields: [], flow: {} }
    const { wrap } = setup(['transaction.view'], () => ({ data: [loan] }))
    wrap(<TransactionTypesPage />)
    fireEvent.change(await screen.findByLabelText('Filter jenis'), { target: { value: 'organization' } })
    expect(await screen.findByText('Belum ada jenis buatan organisasi')).toBeInTheDocument()
    expect(screen.getByText(/1 jenis sistem sudah tersedia/)).toBeInTheDocument()
  })

  it('shows a system type read-only even to a manager, without the flow editor', async () => {
    const loan: TransactionTypeDefinition = { ...mutasi, id: 't-pjm', code: 'PJM', name: 'Peminjaman', system_type: 'loan', fields: [], flow: {} }
    const { wrap } = setup(['transaction.view', 'transaction_type.manage', 'transaction_flow.manage'], (url) =>
      url.includes('/transaction-types/t-pjm') ? { data: loan } : { data: [] }, '/transaksi/jenis/t-pjm')
    wrap(<TransactionTypeFormPage />, '/transaksi/jenis/:id')

    expect(await screen.findByText('Jenis sistem')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Simpan/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Editor alur|Lihat alur/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Hapus jenis/ })).not.toBeInTheDocument()
  })

  it('offers "Jenis baru" to transaction_type.manage', async () => {
    const { wrap } = setup(['transaction.view', 'transaction_type.manage'], () => ({ data: [] }))
    wrap(<TransactionTypesPage />)
    expect((await screen.findAllByRole('link', { name: /Jenis baru/ })).length).toBeGreaterThan(0)
  })
})

describe('transaction form with an organization type', () => {
  it('asks only the start fields and starts a run', async () => {
    const run = { id: 'r1', transaction_number: 'MUT-2026-000012', state: 'running' } as TransactionRun
    const { fetchMock, wrap } = setup(['transaction.view', 'transaction.create'], (url, init) => {
      if (init?.method === 'POST') return { data: run }
      if (url.includes('/flow')) return { data: { active: { id: 'v3', version_number: 3, status: 'published', graph }, draft: null, versions: [] } }
      if (url.startsWith('/api/transaction-types')) return { data: [mutasi] }
      return { data: [] }
    })
    wrap(<TransactionFormModal asset={laptop} onClose={vi.fn()} />)

    const typeSelect = await screen.findByLabelText(/Jenis transaksi/)
    await waitFor(() => expect(screen.getByText('Mutasi Cabang')).toBeInTheDocument())
    fireEvent.change(typeSelect, { target: { value: 'custom:t-mut' } })

    // No. BAST is filled in at "Serah terima", not now.
    const reason = await screen.findByLabelText(/Alasan mutasi/)
    expect(screen.queryByLabelText(/No\. BAST/)).not.toBeInTheDocument()
    expect(await screen.findByText(/No\. BAST diisi di langkah berikutnya/)).toBeInTheDocument()
    fireEvent.change(reason, { target: { value: 'PROYEK' } })

    fireEvent.click((await screen.findByText(/Mulai transaksi/)).closest('button')!)
    await waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true))
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!
    expect(JSON.parse(post[1]!.body as string)).toMatchObject({ type: 'custom', type_definition_id: 't-mut', asset_ids: ['a1'], field_values: { alasan: 'PROYEK' } })
  })
})

describe('running transaction page', () => {
  const runDetail = (canAct: boolean): TransactionRun => ({
    id: 'r1',
    transaction_number: 'MUT-2026-000012',
    state: 'running',
    state_label: 'Berjalan',
    type_definition: { id: 't-mut', code: 'MUT', name: 'Mutasi Cabang' },
    flow_version: 3,
    current_step: { id: 'setuju', type: 'approval', label: 'Persetujuan Supervisor' },
    assets: [{ id: 'a1', name: 'Laptop Dell', locked: true }],
    transaction_date: '2026-11-16',
    notes: null,
    created_by: { id: 'u1', name: 'Andi P.' },
    is_mine: false,
    created_at: '2026-11-16T08:00:00Z',
    ended_at: null,
    field_values: [{ key: 'alasan', label: 'Alasan mutasi', data_type: 'select', value: 'PROYEK', display: 'Kebutuhan proyek' }],
    graph,
    steps: [{ node_id: 'start', type: 'start', label: 'Mulai', outcome: 'started', actor: 'Andi P.', note: null, created_at: '2026-11-16T08:00:00Z' }],
    open_task: { id: 'task1', node_id: 'setuju', kind: 'approval', assignee_type: 'location_supervisor', assignee_id: null, assignee_name: 'Atasan lokasi aset', due_date: null, required_approvals: 1, approvals: 0, can_act: canAct },
  })

  it('shows progress, data and the locked asset; the approver decides', async () => {
    const { fetchMock, wrap } = setup(['transaction.view'], (_url, init) => ({ data: init?.method === 'POST' ? { ...runDetail(false), state_label: 'Berjalan' } : runDetail(true) }), '/transaksi/berjalan/r1')
    wrap(<TransactionRunPage />, '/transaksi/berjalan/:id')

    expect(await screen.findByText('MUT-2026-000012 · Mutasi Cabang')).toBeInTheDocument()
    expect(screen.getAllByText('Persetujuan Supervisor').length).toBeGreaterThan(0)
    expect(screen.getByText('Kebutuhan proyek')).toBeInTheDocument()
    expect(screen.getByText('dikunci')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Setujui atau tolak' }))
    fireEvent.click((await screen.findByText('Setujui')).closest('button')!)
    await waitFor(() => {
      const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
      expect(post?.[0]).toBe('/api/transaction-runs/r1/tasks/task1/complete')
      expect(JSON.parse(post![1]!.body as string)).toMatchObject({ decision: 'approved' })
    })
  })

  it('tells others who the step is waiting for', async () => {
    const { wrap } = setup(['transaction.view'], () => ({ data: runDetail(false) }), '/transaksi/berjalan/r1')
    wrap(<TransactionRunPage />, '/transaksi/berjalan/:id')
    expect(await screen.findByText(/dikerjakan oleh Atasan lokasi aset/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Setujui atau tolak' })).not.toBeInTheDocument()
  })
})
