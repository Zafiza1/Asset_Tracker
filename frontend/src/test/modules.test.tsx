import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ModulesPage } from '../features/organization/ModulesPage'
import type { ModuleCategory, ModuleKey, OrganizationModule, Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

afterEach(() => vi.unstubAllGlobals())

function wrapper(modules: ModuleKey[]) {
  const auth: AuthState = { profile: { user: { id: 'u1' }, permissions: [], modules } as unknown as Profile, isLoading: false, can: () => true, login: vi.fn(), signUp: vi.fn(), logout: vi.fn(), setProfile: vi.fn(), switchOrganization: vi.fn(), createOrganization: vi.fn() }
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <AuthContext.Provider value={auth}>
        <MemoryRouter>{children}</MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>
  )
}

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200 })

/** Fake API: the module list and its category checklist (checked ids kept in `assigned`). */
function fakeApi(enabled: boolean, categories: Omit<ModuleCategory, 'assigned' | 'inherited'>[] = []) {
  const state = { enabled, assigned: new Set<string>() }
  const barcode: OrganizationModule = { key: 'barcode', name: 'Barcode', description: 'Label aset', enabled }
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    if (url.includes('/modules/barcode/categories')) {
      if (init?.method === 'PUT') {
        const body = JSON.parse(init.body as string) as { category_ids: string[]; assigned: boolean }
        body.category_ids.forEach((id) => (body.assigned ? state.assigned.add(id) : state.assigned.delete(id)))
        return json({ data: { changed: body.category_ids.length } })
      }
      const rows = categories.map((c) => ({ ...c, assigned: state.assigned.has(c.id), inherited: c.parent_id !== null && state.assigned.has(c.parent_id) }))
      return json({ data: rows, assigned_assets: rows.filter((r) => r.assigned).reduce((n, r) => n + r.asset_count, 0) })
    }
    if (init?.method === 'PUT') state.enabled = !state.enabled
    return json({ data: [{ ...barcode, enabled: state.enabled }] })
  })
  vi.stubGlobal('fetch', fetchMock)
  return { fetchMock, state }
}

describe('ModulesPage', () => {
  it('switches the Barcode module on', async () => {
    const { fetchMock } = fakeApi(false)
    render(<ModulesPage module="barcode" />, { wrapper: wrapper([]) })

    const toggle = await screen.findByRole('switch', { name: 'Modul Barcode' })
    expect(toggle).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByText('Nonaktif')).toBeInTheDocument()

    fireEvent.click(toggle)
    expect(await screen.findByText('Aktif')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Modul Barcode' })).toHaveAttribute('aria-checked', 'true')
    const put = fetchMock.mock.calls.find(([url, init]) => init?.method === 'PUT' && url.endsWith('/modules/barcode'))!
    expect(JSON.parse(put[1]!.body as string)).toEqual({ enabled: true })
  })

  it('checks a category; its subcategories follow', async () => {
    const { fetchMock, state } = fakeApi(true, [
      { id: 'c1', code: 'IT', name: 'Perangkat IT', parent_id: null, depth: 0, asset_count: 3 },
      { id: 'c2', code: 'LAPTOP', name: 'Laptop', parent_id: 'c1', depth: 1, asset_count: 2 },
      { id: 'c3', code: 'MEBEL', name: 'Mebel', parent_id: null, depth: 0, asset_count: 5 },
    ])
    render(<ModulesPage module="barcode" />, { wrapper: wrapper(['barcode']) })

    const checklist = (await screen.findByRole('heading', { name: 'Kategori aset yang memakai Modul Barcode' })).closest('section')!
    const it = await within(checklist).findByRole('checkbox', { name: 'Perangkat IT' })
    expect(it).not.toBeChecked()

    fireEvent.click(it)
    expect(await within(checklist).findByText('3 aset memakai modul ini')).toBeInTheDocument()
    expect(within(checklist).getByRole('checkbox', { name: 'Perangkat IT' })).toBeChecked()
    // The subcategory is checked through its parent and cannot be unchecked on its own.
    const laptop = within(checklist).getByRole('checkbox', { name: 'Laptop' })
    expect(laptop).toBeChecked()
    expect(laptop).toBeDisabled()
    expect(within(checklist).getByRole('checkbox', { name: 'Mebel' })).not.toBeChecked()
    const put = fetchMock.mock.calls.find(([url, init]) => init?.method === 'PUT' && url.includes('/categories'))!
    expect(JSON.parse(put[1]!.body as string)).toEqual({ category_ids: ['c1'], assigned: true })
    expect([...state.assigned]).toEqual(['c1'])
  })
})
