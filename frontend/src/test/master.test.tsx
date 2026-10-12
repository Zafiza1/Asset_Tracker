import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { tenantNavigation } from '../app/navigation'
import { AttributeValueInput } from '../features/master/attributes'
import { narrowableKeys, parseValidationValue } from '../features/master/attributeRules'
import { MasterListPage } from '../features/master/MasterListPage'
import type { AttributeValue, Department, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'
import { formatAttributeValue } from '../lib/format'

afterEach(() => vi.unstubAllGlobals())

describe('attribute rules', () => {
  it('never offers pattern for tenant narrowing', () => {
    expect(narrowableKeys('string')).toEqual(['min_length', 'max_length'])
    expect(narrowableKeys('enum')).toEqual([])
  })

  it('parses validation inputs into the types the API expects', () => {
    expect(parseValidationValue('integer', 'min', '5')).toBe(5)
    expect(parseValidationValue('decimal', 'max', '2.5')).toBe(2.5)
    expect(parseValidationValue('decimal', 'decimals', '2')).toBe(2)
    expect(parseValidationValue('date', 'min', '2026-01-01')).toBe('2026-01-01')
    expect(parseValidationValue('string', 'pattern', '^[A-Z]+$')).toBe('^[A-Z]+$')
    expect(parseValidationValue('integer', 'max', '')).toBeUndefined()
  })

  it('formats attribute values with option labels', () => {
    const options = [{ value: 'SOLAR', label: 'Solar' }]
    expect(formatAttributeValue('SOLAR', options)).toBe('Solar')
    expect(formatAttributeValue(['SOLAR', 'X'], options)).toBe('Solar, X')
    expect(formatAttributeValue(false)).toBe('Tidak')
    expect(formatAttributeValue(null)).toBe('—')
  })
})

describe('AttributeValueInput', () => {
  function Harness({ spec, onValue }: { spec: Parameters<typeof AttributeValueInput>[0]['spec']; onValue: (v: AttributeValue) => void }) {
    const [value, setValue] = useState<AttributeValue>(null)
    return (
      <label>
        Nilai
        <AttributeValueInput spec={spec} value={value} onChange={(v) => (setValue(v), onValue(v))} />
      </label>
    )
  }

  it('emits numbers for integer attributes and null when cleared', () => {
    const onValue = vi.fn()
    render(<Harness spec={{ data_type: 'integer', options: null }} onValue={onValue} />)
    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '42' } })
    expect(onValue).toHaveBeenLastCalledWith(42)
    fireEvent.change(input, { target: { value: '' } })
    expect(onValue).toHaveBeenLastCalledWith(null)
  })

  it('emits a list for multi-choice attributes', () => {
    const onValue = vi.fn()
    const options = [
      { value: 'A', label: 'Pilihan A' },
      { value: 'B', label: 'Pilihan B' },
    ]
    render(<Harness spec={{ data_type: 'multi_enum', options }} onValue={onValue} />)
    fireEvent.click(screen.getByLabelText('Pilihan B'))
    fireEvent.click(screen.getByLabelText('Pilihan A'))
    expect(onValue).toHaveBeenLastCalledWith(['B', 'A'])
  })
})

describe('MasterListPage', () => {
  const department: Department = {
    id: '01900000-0000-7000-8000-000000000001',
    code: 'OPS',
    name: 'Departemen Operasional',
    status: 'active',
    created_at: '2026-10-12T00:00:00Z',
    updated_at: '2026-10-12T00:00:00Z',
  }

  function renderPage(permissions: string[]) {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ data: [department], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } }), { status: 200 })),
    )
    const can = (p: string) => permissions.includes(p)
    const auth: AuthState = { profile: { permissions } as unknown as Profile, isLoading: false, can, login: vi.fn(), logout: vi.fn(), setProfile: vi.fn() }
    return render(
      <QueryClientProvider client={new QueryClient()}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter>
            <MasterListPage<Department>
              title="Departemen"
              endpoint="/departments"
              canCreate={can('master.tenant.create')}
              canUpdate={can('master.tenant.update')}
              canArchive={can('master.tenant.archive')}
              columns={[{ key: 'name', header: 'Nama', cell: (r) => r.name }]}
              fields={[{ name: 'name', label: 'Nama', required: true }]}
            />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    )
  }

  it('lists records from the API with actions for managers', async () => {
    renderPage(['master.tenant.view', 'master.tenant.create', 'master.tenant.update', 'master.tenant.archive'])
    expect(await screen.findByText('Departemen Operasional')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Tambah/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Arsipkan Departemen Operasional' })).toBeInTheDocument()
    expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/api/departments')
  })

  it('hides write actions for read-only users', async () => {
    renderPage(['master.tenant.view'])
    expect(await screen.findByText('Departemen Operasional')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Tambah/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Arsipkan/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Aksi' })).not.toBeInTheDocument()
  })
})

describe('navigation', () => {
  it('guards every master data menu item with a permission', () => {
    const master = tenantNavigation.find((s) => s.title === 'Master Data')
    expect(master?.items.length).toBeGreaterThan(0)
    for (const item of master!.items) expect(item.permission).toBeTruthy()
  })
})
