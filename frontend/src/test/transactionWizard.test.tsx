import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { FlowGraph, FlowNode, TransactionTypeDefinition } from '../features/transactionTypes/api'
import { fromGraph, newStep, stages, toGraph } from '../features/transactionTypes/simpleFlow'
import { TransactionWizardPage } from '../features/transactionTypes/TransactionWizardPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const n = (id: string, type: FlowNode['type'], label: string, config: FlowNode['config'] = {}): FlowNode => ({ id, type, label, position: { x: 0, y: 0 }, config })
const e = (source: string, target: string, outcome: FlowGraph['edges'][number]['outcome'] = null) => ({ id: `${source}-${target}`, source, target, outcome })

/** Mutasi antar lokasi without its rule steps: approval → isi data → aksi. */
const mutasi: FlowGraph = {
  nodes: [
    n('start', 'start', 'Diajukan'),
    n('setuju_spv', 'approval', 'Persetujuan Supervisor lokasi', { approver: { type: 'location_supervisor' }, min_approvals: 1 }),
    n('terima', 'input', 'Dalam pengiriman', { assignee: { type: 'field', field: 'penerima' }, fields: ['no_bast'] }),
    n('pindah', 'action', 'Diterima', { actions: [{ kind: 'move_to_field_location', field: 'lokasi_tujuan' }] }),
    n('selesai', 'end', 'Selesai', { result: 'completed' }),
    n('ditolak', 'end', 'Ditolak', { result: 'rejected' }),
  ],
  edges: [e('start', 'setuju_spv'), e('setuju_spv', 'terima', 'approved'), e('setuju_spv', 'ditolak', 'rejected'), e('terima', 'pindah'), e('pindah', 'selesai')],
}

const shape = (g: FlowGraph) => ({
  nodes: g.nodes.map((x) => [x.id, x.type, x.label]).sort(),
  edges: g.edges.map((x) => [x.source, x.target, x.outcome]).sort(),
})

describe('simple flow', () => {
  it('reads a straight flow and writes the same flow back', () => {
    const read = fromGraph(mutasi)
    expect(read.reasons).toEqual([])
    expect(read.flow!.approvals.map((a) => a.id)).toEqual(['setuju_spv'])
    expect(read.flow!.input?.id).toBe('terima')
    expect(stages(read.flow!)).toEqual(['Diajukan', 'Persetujuan Supervisor lokasi', 'Dalam pengiriman', 'Diterima', 'Selesai'])
    expect(shape(toGraph(read.flow!))).toEqual(shape(mutasi))
  })

  it('adds and removes approvals with a single Ditolak end', () => {
    const flow = fromGraph(mutasi).flow!
    const two = { ...flow, approvals: [...flow.approvals, newStep(flow, 'approval')] }
    const graph = toGraph(two)
    expect(two.approvals[1].id).toBe('setuju')
    expect(graph.edges.filter((x) => x.outcome === 'rejected').map((x) => x.target)).toEqual(['ditolak', 'ditolak'])
    expect(graph.edges.find((x) => x.source === 'setuju_spv' && x.outcome === 'approved')?.target).toBe('setuju')

    const none = toGraph({ ...flow, approvals: [] })
    expect(none.nodes.some((x) => x.id === 'ditolak')).toBe(false)
    expect(none.edges.find((x) => x.source === 'start')?.target).toBe('terima')
  })

  it('tells why a flow needs the advanced mode, without changing it', () => {
    const withCondition: FlowGraph = {
      nodes: [n('start', 'start', 'Mulai'), n('cek', 'condition', 'Hasil baik?', { rules: [] }), n('ok', 'end', 'Selesai', { result: 'completed' })],
      edges: [e('start', 'cek'), e('cek', 'ok', 'yes'), e('cek', 'ok', 'no')],
    }
    expect(fromGraph(withCondition).reasons[0]).toMatch(/Kondisi "Hasil baik\?" membuat cabang/)

    const three = { ...mutasi, nodes: [...mutasi.nodes, n('a2', 'approval', 'A2'), n('a3', 'approval', 'A3')], edges: [
      e('start', 'setuju_spv'), e('setuju_spv', 'a2', 'approved'), e('a2', 'a3', 'approved'), e('a3', 'terima', 'approved'),
      e('setuju_spv', 'ditolak', 'rejected'), e('a2', 'ditolak', 'rejected'), e('a3', 'ditolak', 'rejected'), e('terima', 'pindah'), e('pindah', 'selesai'),
    ] }
    expect(fromGraph(three).reasons.join(' ')).toMatch(/3 persetujuan/)

    const backwards: FlowGraph = { ...mutasi, edges: [e('start', 'terima'), e('terima', 'setuju_spv'), e('setuju_spv', 'pindah', 'approved'), e('setuju_spv', 'ditolak', 'rejected'), e('pindah', 'selesai')] }
    expect(fromGraph(backwards).reasons.join(' ')).toMatch(/berurutan: persetujuan, isi data, aksi, tunggu/)

    const stray: FlowGraph = { ...mutasi, nodes: [...mutasi.nodes, n('lain', 'action', 'Lain', { actions: [] })] }
    expect(fromGraph(stray).reasons.join(' ')).toMatch(/di luar jalur utama: "Lain"/)
  })
})

const type: TransactionTypeDefinition = {
  id: 't1', code: 'MUT', name: 'Mutasi antar lokasi', description: 'Memindah aset.', icon: null, status: 'draft', multi_asset: true, locks_assets: true,
  source_template_key: 'mutasi_lokasi', categories: [],
  fields: [
    { key: 'lokasi_tujuan', label: 'Lokasi tujuan', data_type: 'location', settings: {}, is_required: true },
    { key: 'penerima', label: 'Penerima', data_type: 'user', settings: {}, is_required: true },
    { key: 'no_bast', label: 'No. BAST', data_type: 'text', settings: {}, is_required: false },
  ],
  created_at: '2026-11-23T00:00:00Z', updated_at: '2026-11-23T00:00:00Z',
}
const rules = [{ id: 'nilai', name: 'Approval aset bernilai tinggi', description: null, priority: 1, active: true, match: 'all', assets: 'any', conditions: [], outcome: { kind: 'approval', approver: { type: 'location_supervisor' } } }]

function setup(graph: FlowGraph) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'
    let data: unknown = []
    if (url.includes('/flow/scenarios')) {
      return new Response(JSON.stringify({ data: [scenario], meta: { total: 1, truncated: false, limit: 12 } }), { status: 200 })
    }
    if (url.includes('/flow/simulate')) data = { path: scenario.steps.map((x) => ({ node_id: x.node_id, type: x.type, label: x.label, outcome: x.outcome })) }
    else if (url.includes('/flow/describe')) data = { title: 'Mutasi', purpose: null, records_at_once: false, who_records: 'Semua.', preconditions: null, form: [], steps: [], effects: [], people: [], summary: 'Perlu disetujui Supervisor.', rules: [], changes: [] }
    else if (url.includes('/flow/publish')) data = { id: 'v1', version_number: 1, status: 'published', graph }
    else if (url.includes('/flow') && method === 'PUT') data = { id: 'v1', version_number: 1, status: 'draft', graph, rules, graph_source: 'rules', base_graph: graph, issues: [] }
    else if (url.includes('/flow')) data = { active: null, draft: { id: 'v1', version_number: 1, status: 'draft', graph, rules, graph_source: 'rules', base_graph: graph, change_note: null, published_at: null, updated_at: null, issues: [] }, versions: [] }
    else if (url.includes('/transaction-flow-options')) data = { roles: [{ id: 'spv', name: 'Supervisor', may_approve: true }], members: [], action_statuses: [{ value: 'in_use', label: 'Aktif' }], asset_statuses: [], conditions: [], max_approvals: 5, max_message: 500 }
    else if (url.startsWith('/api/transaction-types/t1')) data = type
    return new Response(JSON.stringify({ data }), { status: method === 'POST' ? 201 : 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const permissions = ['transaction.view', 'transaction_type.manage', 'transaction_flow.manage']
  const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can: (p: string) => permissions.includes(p), login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter initialEntries={['/transaksi/jenis/t1/panduan']}>
          <Routes>
            <Route path="/transaksi/jenis/:id/panduan" element={<TransactionWizardPage />} />
            <Route path="/transaksi/jenis/:id" element={<p>Halaman jenis</p>} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
  const sent = (method: string, part: string) => fetchMock.mock.calls.filter(([u, i]) => (i?.method ?? 'GET') === method && String(u).includes(part)).map(([, i]) => JSON.parse(String(i!.body ?? '{}')))
  return { fetchMock, sent }
}

const next = () => fireEvent.click(screen.getByRole('button', { name: /Lanjut/ }))

const scenario = {
  id: 's1', kind: 'path' as const, name: 'Disetujui Supervisor yang membawahi aset → Selesai',
  steps: [
    { node_id: 'start', label: 'Diajukan', type: 'start' as const, outcome: null },
    { node_id: 'setuju_spv', label: 'Persetujuan Supervisor lokasi', type: 'approval' as const, outcome: 'approved' },
    { node_id: 'selesai', label: 'Selesai', type: 'end' as const, outcome: null },
  ],
  end: { node_id: 'selesai', label: 'Selesai', result: 'completed' as const }, message: null, rule_id: null,
  inputs: { asset_ids: ['a1'], field_values: {}, decisions: { setuju_spv: 'approved' } }, status: 'ready' as const, note: null,
}

describe('designer wizard', () => {
  it('saves each step: the type, then the flow with its rules, and publishes', async () => {
    const { sent } = setup(mutasi)
    expect(await screen.findByDisplayValue('Mutasi antar lokasi')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Informasi/ })).toHaveAttribute('aria-current', 'step')

    fireEvent.change(screen.getByLabelText(/Nama transaksi/), { target: { value: 'Mutasi gudang' } })
    next()
    await waitFor(() => expect(sent('PUT', '/transaction-types/t1').some((b) => b.name === 'Mutasi gudang')).toBe(true))

    // Formulir: the preview leaves out what is filled in at a later step.
    expect(await screen.findByText(/Diisi di langkah "Dalam pengiriman": No\. BAST/)).toBeInTheDocument()
    next()
    expect(await screen.findByText('Tahapan yang dilihat pengguna')).toBeInTheDocument()
    next()

    // Persetujuan: a second approval in a row; the named rules stay with the flow.
    fireEvent.click(await screen.findByRole('button', { name: 'Dua tingkat' }))
    expect(screen.getByText(/Approval aset bernilai tinggi · Perlu persetujuan/)).toBeInTheDocument()
    next()
    await waitFor(() => expect(sent('PUT', '/flow').length).toBe(1))
    const flow = sent('PUT', '/flow')[0]
    expect(flow.rules).toEqual(rules)
    expect(flow.graph.nodes.filter((x: FlowNode) => x.type === 'approval').map((x: FlowNode) => x.id)).toEqual(['setuju_spv', 'setuju'])

    // Aksi, then Tinjau with "Cara kerja"; publishing waits for the scenarios (F08-09).
    expect(await screen.findByText('Transaksi ini mengubah aset.')).toBeInTheDocument()
    next()
    expect(await screen.findByText('Perlu disetujui Supervisor.')).toBeInTheDocument()
    expect(await screen.findByText(scenario.name)).toBeInTheDocument()
    expect(screen.getByText('Belum dijalankan')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Terbitkan v1/ })).toBeDisabled()
    expect(screen.getByText('Jalankan semua skenario terlebih dahulu.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Jalankan semua skenario \(1\)/ }))
    expect(await screen.findByText('Lulus')).toBeInTheDocument()
    expect(sent('POST', '/flow/simulate')[0]).toMatchObject({ asset_ids: ['a1'], decisions: { setuju_spv: 'approved' } })

    // The change note is suggested (a first version) and goes with the published draft.
    expect(screen.getByLabelText(/Catatan perubahan/)).toHaveValue('Versi pertama.')
    fireEvent.change(screen.getByLabelText(/Catatan perubahan/), { target: { value: 'Dua tingkat persetujuan.' } })
    fireEvent.click(screen.getByRole('button', { name: /Terbitkan v1/ }))
    expect(await screen.findByText('Halaman jenis')).toBeInTheDocument()
    expect(sent('PUT', '/flow').at(-1)).toMatchObject({ change_note: 'Dua tingkat persetujuan.', rules })
    expect(sent('POST', '/flow/publish')).toHaveLength(1)
    expect(sent('PUT', '/transaction-types/t1').some((b) => b.status === 'active')).toBe(true)
  })

  it('asks to run a scenario again after the flow changed', async () => {
    setup(mutasi)
    await screen.findByDisplayValue('Mutasi antar lokasi')
    fireEvent.click(screen.getByRole('button', { name: /Tinjau/ }))
    fireEvent.click(await screen.findByRole('button', { name: /Jalankan semua skenario/ }))
    expect(await screen.findByText('Lulus')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Persetujuan/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Tidak perlu' }))
    fireEvent.click(screen.getByRole('button', { name: /Tinjau/ }))
    expect(await screen.findByText('Perlu dijalankan ulang')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Terbitkan v1/ })).toBeDisabled()
  })

  it('shows why a flow is advanced and leaves it unchanged', async () => {
    const advanced: FlowGraph = {
      nodes: [n('start', 'start', 'Mulai'), n('cek', 'condition', 'Hasil baik?', { rules: [] }), n('ok', 'end', 'Selesai', { result: 'completed' })],
      edges: [e('start', 'cek'), e('cek', 'ok', 'yes'), e('cek', 'ok', 'no')],
    }
    const { sent } = setup(advanced)
    await screen.findByDisplayValue('Mutasi antar lokasi')
    fireEvent.click(screen.getByRole('button', { name: /Alur/ }))
    expect(await screen.findByText('Alur ini memakai mode lanjutan')).toBeInTheDocument()
    expect(screen.getByText(/Kondisi "Hasil baik\?" membuat cabang/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Buka mode lanjutan' })).toHaveAttribute('href', '/transaksi/jenis/t1/alur')
    next()
    next()
    next()
    await screen.findByText('Perlu disetujui Supervisor.')
    expect(sent('PUT', '/flow')).toHaveLength(0)
  })
})
