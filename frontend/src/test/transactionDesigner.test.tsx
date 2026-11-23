import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { FlowDocumentation, FlowGraph, NamedRule, Template, TransactionTypeDefinition } from '../features/transactionTypes/api'
import { byPriority, codeFromName, operatorsFor, renumber, rulePreview } from '../features/transactionTypes/rulesLogic'
import { TransactionRulesPage } from '../features/transactionTypes/TransactionRulesPage'
import { TransactionTypeStartPage } from '../features/transactionTypes/TransactionTypeStartPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const doc = (title: string, extra: Partial<FlowDocumentation> = {}): FlowDocumentation => ({
  title,
  purpose: 'Memindah aset ke lokasi lain.',
  records_at_once: false,
  who_records: 'Dapat dicatat oleh semua pengguna yang berizin mencatat transaksi.',
  preconditions: null,
  form: [{ key: 'lokasi_tujuan', label: 'Lokasi tujuan', type: 'Lokasi', required: true, help: null, asked_in: null }],
  steps: [{ number: 1, id: 'setuju', type: 'approval', label: 'Persetujuan Supervisor lokasi', sentence: 'Perlu disetujui Supervisor yang membawahi aset.' }],
  effects: ['Aset dipindah ke lokasi pada field Lokasi tujuan'],
  people: [],
  summary: 'Transaksi ini perlu disetujui Supervisor yang membawahi aset sebelum aset dipindah.',
  rules: [],
  changes: [],
  ...extra,
})

const graph: FlowGraph = {
  nodes: [
    { id: 'start', type: 'start', label: 'Diajukan', position: { x: 0, y: 0 }, config: {} },
    { id: 'setuju', type: 'approval', label: 'Persetujuan Supervisor lokasi', position: { x: 0, y: 0 }, config: { approver: { type: 'location_supervisor' } } },
    { id: 'ok', type: 'end', label: 'Selesai', position: { x: 0, y: 0 }, config: { result: 'completed' } },
    { id: 'tolak', type: 'end', label: 'Ditolak', position: { x: 0, y: 0 }, config: { result: 'rejected' } },
  ],
  edges: [
    { id: 'e1', source: 'start', target: 'setuju', outcome: null },
    { id: 'e2', source: 'setuju', target: 'ok', outcome: 'approved' },
    { id: 'e3', source: 'setuju', target: 'tolak', outcome: 'rejected' },
  ],
}

const template = (key: string, name: string, extra: Partial<Template> = {}): Template => ({
  key,
  name,
  category: 'perpindahan',
  purpose: `Kegunaan ${name}.`,
  code: 'MUT',
  icon: 'truck',
  core_type: null,
  field_count: 5,
  approval_count: 3,
  fields: [],
  graph,
  rules: [],
  documentation: doc(name),
  pair: null,
  ...extra,
})

const type: TransactionTypeDefinition = {
  id: 't1', code: 'MUT', name: 'Mutasi antar lokasi', description: null, icon: null, status: 'draft', multi_asset: true, locks_assets: true,
  fields: [{ key: 'estimasi', label: 'Estimasi biaya', data_type: 'money', settings: {}, is_required: false }],
  created_at: '2026-11-23T00:00:00Z', updated_at: '2026-11-23T00:00:00Z',
}

const rule = (id: string, name: string, priority: number, extra: Partial<NamedRule> = {}): NamedRule => ({
  id, name, description: null, priority, active: true, match: 'all', assets: 'any',
  conditions: [{ subject: 'asset.acquisition_cost', op: 'gt', value: 10000000 }],
  outcome: { kind: 'approval', approver: { type: 'location_supervisor' }, min_approvals: 1 },
  ...extra,
})

function setup(respond: (url: string, init?: RequestInit) => unknown, path: string, route: string, ui: ReactNode) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const answer = respond(url, init) as { error?: unknown }
    return new Response(JSON.stringify(answer), { status: answer.error ? 422 : init?.method === 'POST' ? 201 : 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const permissions = ['transaction.view', 'transaction_type.manage', 'transaction_flow.manage']
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can: (p: string) => permissions.includes(p), login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path={route} element={ui} />
            <Route path="/transaksi/jenis/:id" element={<p>Halaman jenis</p>} />
            <Route path="/transaksi/jenis/:id/panduan" element={<p>Halaman jenis</p>} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  return fetchMock
}

const body = (fetchMock: ReturnType<typeof setup>, method: string, url: string) => {
  const call = [...fetchMock.mock.calls].reverse().find(([u, init]) => init?.method === method && String(u).includes(url))
  return call ? JSON.parse(call[1]!.body as string) : undefined
}

describe('designer helpers', () => {
  it('orders and renumbers rules, reads operators per subject and suggests a code', () => {
    const rules = [rule('b', 'B', 2), rule('a', 'A', 1), rule('c', 'C', 2)]
    expect(byPriority(rules).map((r) => r.id)).toEqual(['a', 'b', 'c'])
    expect(renumber(byPriority(rules)).map((r) => r.priority)).toEqual([1, 2, 3])
    expect(operatorsFor('asset.custodian', false).map((o) => o.value)).toEqual(['not_empty', 'empty'])
    expect(operatorsFor('asset.acquisition_cost', true).map((o) => o.value)).toContain('gt')
    expect(operatorsFor('asset.status', false).map((o) => o.value)).not.toContain('gt')
    expect(codeFromName('Kirim Kalibrasi')).toBe('KK')
    expect(codeFromName('Kalibrasi')).toBe('KAL')
    expect(rulePreview(rule('a', 'A', 1), [])).toBe('Bila nilai perolehan lebih dari 10.000.000, perlu persetujuan.')
    expect(rulePreview(rule('a', 'A', 1, { conditions: [] }), [])).toBe('Selalu perlu persetujuan.')
  })
})

describe('new type: template first', () => {
  const templates = [
    template('mutasi_lokasi', 'Mutasi antar lokasi'),
    template('kirim_kalibrasi', 'Kirim kalibrasi', { category: 'perawatan', code: 'KAL', pair: template('kembali_kalibrasi', 'Kembali dari kalibrasi', { code: 'KBL' }) }),
  ]
  const respond = (url: string, init?: RequestInit) => {
    if (url.startsWith('/api/transaction-type-templates')) return { data: templates, meta: { categories: { perpindahan: 'Perpindahan', peminjaman: 'Peminjaman', perawatan: 'Perawatan', penghapusan: 'Penghapusan' } } }
    if (url.includes('/from-template')) {
      const sent = JSON.parse(String(init?.body))
      if (sent.code === 'MUT') return { error: { code: 'VALIDATION_FAILED', message: 'Data tidak valid.', details: { fields: { code: ['Kode MUT sudah dipakai jenis lain.'] } } } }
      return { data: { ...type, id: 't-new', name: sent.name }, meta: { pair: null } }
    }
    if (url.startsWith('/api/transaction-types')) return { data: [{ ...type, id: 'sys', name: 'Peminjaman', system_type: 'loan', code: 'PJM', description: 'Meminjamkan aset.' }] }
    return { data: [] }
  }

  it('shows the cards by group and how a template works without making anything', async () => {
    const fetchMock = setup(respond, '/transaksi/jenis/baru', '/transaksi/jenis/baru', <TransactionTypeStartPage />)
    expect(await screen.findByText('Mutasi antar lokasi')).toBeInTheDocument()
    expect(screen.getByText(/dibuat bersama "Kembali dari kalibrasi"/)).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Peminjaman' })).toBeInTheDocument()
    expect(screen.getByText('Mulai dari kosong')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: /Perawatan/ }))
    expect(screen.queryByText('Mutasi antar lokasi')).not.toBeInTheDocument()
    expect(screen.queryByText('Mulai dari kosong')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Lihat cara kerja' }))
    expect(await screen.findByText('Cara kerja: Kirim kalibrasi')).toBeInTheDocument()
    expect(screen.getByText(/perlu disetujui Supervisor yang membawahi aset sebelum aset dipindah/)).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
  })

  it('makes a draft from a template, with the pair and the server checks on the code', async () => {
    const fetchMock = setup(respond, '/transaksi/jenis/baru', '/transaksi/jenis/baru', <TransactionTypeStartPage />)
    const card = (await screen.findByText('Mutasi antar lokasi')).closest('li')!
    fireEvent.click(within(card).getByRole('button', { name: 'Pakai template' }))
    fireEvent.click((await screen.findByText('Buat draft')).closest('button')!)
    expect(await screen.findByText('Kode MUT sudah dipakai jenis lain.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText(/Kode \/ prefix nomor/), { target: { value: 'mut2b' } })
    expect((screen.getByLabelText(/Kode \/ prefix nomor/) as HTMLInputElement).value).toBe('MUTB')
    fireEvent.click(screen.getByText('Buat draft').closest('button')!)
    expect(await screen.findByText('Halaman jenis')).toBeInTheDocument()
    expect(body(fetchMock, 'POST', '/from-template')).toEqual({ template: 'mutasi_lokasi', name: 'Mutasi antar lokasi', code: 'MUTB' })
  })

  it('sends the pair of a paired template', async () => {
    const fetchMock = setup(respond, '/transaksi/jenis/baru', '/transaksi/jenis/baru', <TransactionTypeStartPage />)
    const card = (await screen.findByText('Kirim kalibrasi')).closest('li')!
    fireEvent.click(within(card).getByRole('button', { name: 'Pakai template' }))
    fireEvent.change(await screen.findByLabelText(/Kode pasangan/), { target: { value: 'KBLB' } })
    fireEvent.click(screen.getByText('Buat draft').closest('button')!)
    await screen.findByText('Halaman jenis')
    expect(body(fetchMock, 'POST', '/from-template')).toMatchObject({ template: 'kirim_kalibrasi', code: 'KAL', pair: { name: 'Kembali dari kalibrasi', code: 'KBLB' } })
  })
})

describe('rules tab', () => {
  const rules = [rule('nilai', 'Approval aset bernilai tinggi', 1), rule('rusak', 'Larangan memindah aset rusak', 2, { outcome: { kind: 'reject', message: 'Perbaiki dulu.' } })]
  const respond = (source: 'rules' | 'canvas') => (url: string, init?: RequestInit) => {
    if (url.includes('/flow/describe')) {
      return { data: doc('Mutasi', { rules: [
        { id: 'nilai', name: 'Approval aset bernilai tinggi', description: null, priority: 1, active: true, kind: 'approval', sentence: 'Bila nilai perolehan lebih dari Rp 10.000.000, perlu disetujui pemegang role Super Admin.', applies: true, note: null },
        { id: 'rusak', name: 'Larangan memindah aset rusak', description: null, priority: 2, active: true, kind: 'reject', sentence: 'Bila kondisi aset adalah Rusak Berat, transaksi tidak dapat dicatat.', applies: false, note: 'Bertentangan dengan rule "Approval aset bernilai tinggi" yang prioritasnya lebih tinggi; rule ini tidak berlaku.' },
      ] }) }
    }
    if (url.includes('/flow') && init?.method === 'PUT') return { data: { id: 'v1', version_number: 1, status: 'draft', graph, rules: JSON.parse(String(init.body)).rules, graph_source: 'rules', base_graph: graph, change_note: null, published_at: null, updated_at: null, issues: [] } }
    if (url.includes('/flow')) return { data: { active: null, draft: { id: 'v1', version_number: 1, status: 'draft', graph, rules, graph_source: source, base_graph: graph, change_note: null, published_at: null, updated_at: null, issues: [] }, versions: [] } }
    if (url.includes('/transaction-flow-options')) return { data: { roles: [{ id: 'sa', name: 'Super Admin', may_approve: true }], members: [], action_statuses: [], asset_statuses: [], conditions: [], max_approvals: 5, max_message: 500 } }
    if (url.startsWith('/api/transaction-types/t1')) return { data: type }
    return { data: [] }
  }

  it('lists the rules by priority with their sentences and conflicts, and saves changes with the flow', async () => {
    const fetchMock = setup(respond('rules'), '/transaksi/jenis/t1/rules', '/transaksi/jenis/:id/rules', <TransactionRulesPage />)
    expect(await screen.findByText('Bila nilai perolehan lebih dari Rp 10.000.000, perlu disetujui pemegang role Super Admin.')).toBeInTheDocument()
    expect(screen.getByText('Bertentangan')).toBeInTheDocument()
    expect(screen.getByText(/rule ini tidak berlaku/, { selector: 'li' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Rules/ })).toHaveAttribute('aria-current', 'page')

    // Moving the second rule up renumbers the priorities; the server gets the flow without rule steps.
    fireEvent.click(screen.getByRole('button', { name: 'Naikkan Larangan memindah aset rusak' }))
    await waitFor(() => expect(body(fetchMock, 'PUT', '/flow')).toBeDefined())
    const sent = body(fetchMock, 'PUT', '/flow')
    expect(sent.rules.map((r: NamedRule) => [r.id, r.priority])).toEqual([['rusak', 1], ['nilai', 2]])
    expect(sent.graph).toEqual(graph)
  })

  it('adds a rule from the JIKA … MAKA … dialog, with a name from its outcome when left empty', async () => {
    const fetchMock = setup(respond('rules'), '/transaksi/jenis/t1/rules', '/transaksi/jenis/:id/rules', <TransactionRulesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Rule baru' }))
    fireEvent.click((await screen.findAllByText('Tolak transaksi')).find((el) => el.closest('dialog') && el.closest('button'))!.closest('button')!)
    fireEvent.change(screen.getByLabelText(/Pesan untuk pencatat/), { target: { value: 'Terlalu mahal.' } })
    expect(screen.getByText('Bila nilai perolehan lebih dari 10.000.000, transaksi ditolak.')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Simpan rule').closest('button')!)

    await waitFor(() => expect(body(fetchMock, 'PUT', '/flow')).toBeDefined())
    const added = body(fetchMock, 'PUT', '/flow').rules[2]
    expect(added).toMatchObject({ name: 'Tolak transaksi bila nilai perolehan', priority: 3, active: true, outcome: { kind: 'reject', message: 'Terlalu mahal.' } })
    expect(added.id).toMatch(/^[a-z0-9]{1,12}$/)
  })

  it('shows the rules for reading when the flow was changed on the canvas', async () => {
    const fetchMock = setup(respond('canvas'), '/transaksi/jenis/t1/rules', '/transaksi/jenis/:id/rules', <TransactionRulesPage />)
    expect(await screen.findByText('Alur diubah di mode lanjutan')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rule baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ubah Approval/ })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Pakai rules lagi' }))
    await waitFor(() => expect(body(fetchMock, 'PUT', '/flow')?.rules).toHaveLength(2))
  })
})
