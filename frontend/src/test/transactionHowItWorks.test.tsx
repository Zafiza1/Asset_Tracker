import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Scenario } from '../features/transactionTypes/api'
import { runScenario, scenariosPassed } from '../features/transactionTypes/scenarioLogic'
import { TransactionHowItWorksPage } from '../features/transactionTypes/TransactionHowItWorksPage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

const doc = (version: number) => ({
  title: 'Mutasi', purpose: null, records_at_once: false, who_records: 'Semua.', preconditions: null, form: [], effects: [], people: [], rules: [],
  steps: [{ number: 1, id: 'setuju', type: 'approval', label: `Persetujuan v${version}`, sentence: 'Perlu disetujui Supervisor.' }],
  summary: `Ringkasan versi ${version}.`,
  changes: version === 2 ? ['Langkah "Persetujuan Super Admin" ditambahkan: Perlu disetujui pemegang role Super Admin.'] : [],
  previous_version: version === 2 ? 1 : null,
})

const scenario = (extra: Partial<Scenario> = {}): Scenario => ({
  id: 's1', kind: 'path', name: 'Disetujui → Selesai',
  steps: [{ node_id: 'start', label: 'Mulai', type: 'start', outcome: null }, { node_id: 'setuju', label: 'Setuju', type: 'approval', outcome: 'approved' }, { node_id: 'ok', label: 'Selesai', type: 'end', outcome: null }],
  end: { node_id: 'ok', label: 'Selesai', result: 'completed' }, message: null, rule_id: null,
  inputs: { asset_ids: ['a1'], field_values: {}, decisions: { setuju: 'approved' } }, status: 'ready', note: null,
  ...extra,
})

describe('scenario results', () => {
  it('fails a scenario whose dry run took another path, and needs every runnable one passed on this flow', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ data: { path: [
      { node_id: 'start', type: 'start', label: 'Mulai', outcome: null },
      { node_id: 'setuju', type: 'approval', label: 'Setuju', outcome: 'rejected' },
      { node_id: 'tolak', type: 'end', label: 'Ditolak', outcome: null },
    ] } }), { status: 200 })))
    const result = await runScenario('t1', scenario(), 'k1')
    expect(result).toEqual({ passed: false, key: 'k1', detail: 'Mulai → Setuju (Ditolak) → Ditolak' })

    const list = [scenario(), scenario({ id: 's2', status: 'no_sample' })]
    expect(scenariosPassed(list, { s1: { passed: true, key: 'k1', detail: null } }, 'k1')).toBe(true)
    expect(scenariosPassed(list, { s1: { passed: true, key: 'k0', detail: null } }, 'k1')).toBe(false)
    expect(scenariosPassed([scenario({ status: 'problem' })], {}, 'k1')).toBe(false)
    expect(scenariosPassed(undefined, {}, 'k1')).toBe(false)
  })
})

describe('Cara kerja tab', () => {
  it('shows the active version for viewers, with its changes, and any other version on request', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      let data: unknown
      if (url.includes('/flow/describe')) data = doc(url.includes('version=1') ? 1 : 2)
      else if (url.includes('/flow')) data = { active: { id: 'v2', version_number: 2, status: 'published' }, draft: null, versions: [
        { version_number: 2, status: 'published', change_note: 'Tambah Super Admin.', published_at: '2026-11-22T03:00:00Z', published_by: 'Sari' },
        { version_number: 1, status: 'published', change_note: null, published_at: '2026-11-20T03:00:00Z', published_by: 'Sari' },
      ] }
      else data = { id: 't1', code: 'MUT', name: 'Mutasi', status: 'active', fields: [] }
      return new Response(JSON.stringify({ data }), { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)
    const permissions = ['transaction.view']
    const auth: AuthState = { profile: { permissions, modules: [] } as unknown as Profile, isLoading: false, can: (p: string) => permissions.includes(p), login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={['/transaksi/jenis/t1/cara-kerja']}>
            <Routes>
              <Route path="/transaksi/jenis/:id/cara-kerja" element={<TransactionHowItWorksPage />} />
            </Routes>
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Ringkasan versi 2.')).toBeInTheDocument()
    expect(screen.getByText('Perubahan dari versi 1')).toBeInTheDocument()
    expect(screen.getByText(/Catatan: Tambah Super Admin\./)).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/flow/describe?version=2'))).toBe(true)
    expect(screen.getByRole('button', { name: /Cetak/ })).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Versi'), { target: { value: '1' } })
    await waitFor(() => expect(screen.getByText('Ringkasan versi 1.')).toBeInTheDocument())
    expect(screen.queryByText(/Perubahan dari versi/)).not.toBeInTheDocument()
  })
})
