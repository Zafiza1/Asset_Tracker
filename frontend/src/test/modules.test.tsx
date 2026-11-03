import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, renderHook, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useAssetFields } from '../features/master/categoryAttributeData'
import { ModulesPage } from '../features/organization/ModulesPage'
import type { CategoryAttribute, ModuleKey, OrganizationModule, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

function wrapper(modules: ModuleKey[]) {
  const auth: AuthState = { profile: { user: { id: 'u1' }, permissions: [], modules } as unknown as Profile, isLoading: false, can: () => true, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  const client = new QueryClient()
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>{children}</MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

describe('ModulesPage', () => {
  it('switches the Barcode module on', async () => {
    const barcode: OrganizationModule = { key: 'barcode', name: 'Barcode', description: 'Label aset', enabled: false }
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
      new Response(JSON.stringify({ data: [{ ...barcode, enabled: init?.method === 'PUT' }] }), { status: 200 }),
    )
    vi.stubGlobal('fetch', fetchMock)
    render(<ModulesPage module="barcode" />, { wrapper: wrapper([]) })

    const toggle = await screen.findByRole('switch', { name: 'Modul Barcode' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByText('Nonaktif')).toBeInTheDocument()

    fireEvent.click(toggle)
    expect(await screen.findByText('Aktif')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Modul Barcode' })).toHaveAttribute('aria-checked', 'true')
    const put = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')!
    expect(put[0]).toContain('/modules/barcode')
    expect(JSON.parse(put[1]!.body as string)).toEqual({ enabled: true })
  })
})

describe('useAssetFields', () => {
  const fields = [
    { key: 'merek', label: 'Merek', is_default: true },
    { key: 'barcode', label: 'Barcode', is_default: true },
    // A custom field that happens to be called Barcode is not the module's field.
    { key: 'barcode_2', label: 'Barcode', is_default: false },
  ] as CategoryAttribute[]

  it('hides the default Barcode field while the module is off', () => {
    const { result } = renderHook(() => useAssetFields(fields), { wrapper: wrapper([]) })
    expect(result.current.map((f) => f.key)).toEqual(['merek', 'barcode_2'])
  })

  it('shows it once the module is on', () => {
    const { result } = renderHook(() => useAssetFields(fields), { wrapper: wrapper(['barcode']) })
    expect(result.current.map((f) => f.key)).toEqual(['merek', 'barcode', 'barcode_2'])
  })
})
