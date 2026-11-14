import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { Boxes, FileClock, LayoutDashboard } from 'lucide-react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { GlobalSearch } from '../app/GlobalSearch'
import type { NavSection } from '../app/navigation'

afterEach(() => vi.unstubAllGlobals())

// The menu as the layout passes it: already limited to what the user may open.
const sections: NavSection[] = [
  { items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard }] },
  { title: 'Manajemen Aset', items: [{ to: '/aset', label: 'Data Aset', icon: Boxes }] },
  { title: 'Administrasi', items: [{ to: '/audit', label: 'Audit Trail', icon: FileClock }] },
]

const laptop = { id: 'asset-1', serial_number: 'SN-0001', name: 'Laptop Dell', status: 'in_use', is_archived: false, category: { name: 'IT' }, location: { name: 'Gedung A' } }

function Where() {
  const { pathname, search } = useLocation()
  return <p data-testid="where">{pathname + search}</p>
}

function renderSearch({ searchAssets = true, total = 1 } = {}) {
  const requests: string[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      requests.push(String(input))
      return new Response(JSON.stringify({ data: [laptop], meta: { current_page: 1, last_page: 1, per_page: 6, total } }), { status: 200 })
    }),
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/']}>
        <GlobalSearch sections={sections} searchAssets={searchAssets} />
        <Routes>
          <Route path="*" element={<Where />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return requests
}

// jsdom does not open <dialog>, so its content stays out of the accessibility tree.
const palette = () => screen.getByRole('dialog', { hidden: true })
const option = (name: RegExp) => within(palette()).getByRole('option', { name, hidden: true })

describe('GlobalSearch', () => {
  it('opens from the top bar and with Ctrl+K, listing the menus the user may open', () => {
    renderSearch()
    expect(screen.queryByRole('dialog', { hidden: true })).not.toBeInTheDocument()

    fireEvent.keyDown(document, { key: 'k', ctrlKey: true })
    expect(option(/Dashboard/)).toBeInTheDocument()
    expect(option(/Audit Trail/)).toBeInTheDocument()
  })

  it('narrows the menus as the user types and opens the highlighted one with Enter', () => {
    renderSearch({ searchAssets: false })
    fireEvent.click(screen.getByRole('button', { name: 'Cari menu' }))
    const input = within(palette()).getByRole('combobox', { hidden: true })

    fireEvent.change(input, { target: { value: 'audit' } })
    expect(within(palette()).queryByRole('option', { name: /Dashboard/, hidden: true })).not.toBeInTheDocument()
    expect(option(/Audit Trail/)).toHaveAttribute('aria-selected', 'true')

    fireEvent.keyDown(input, { key: 'Enter' })
    expect(screen.getByTestId('where')).toHaveTextContent('/audit')
    expect(screen.queryByRole('dialog', { hidden: true })).not.toBeInTheDocument()
  })

  it('finds assets by keyword and opens the chosen asset', async () => {
    const requests = renderSearch()
    fireEvent.click(screen.getByRole('button', { name: 'Cari aset atau menu' }))
    fireEvent.change(within(palette()).getByRole('combobox', { hidden: true }), { target: { value: 'laptop' } })

    const found = await within(palette()).findByRole('option', { name: /Laptop Dell/, hidden: true })
    expect(found).toHaveTextContent('SN-0001 · IT · Gedung A')
    expect(requests.at(-1)).toContain('/api/assets?search=laptop&per_page=6')

    fireEvent.click(found)
    expect(screen.getByTestId('where')).toHaveTextContent('/aset/asset-1')
  })

  it('links to the full list when more assets match than it shows', async () => {
    renderSearch({ total: 240 })
    fireEvent.click(screen.getByRole('button', { name: 'Cari aset atau menu' }))
    fireEvent.change(within(palette()).getByRole('combobox', { hidden: true }), { target: { value: 'laptop' } })

    fireEvent.click(await within(palette()).findByRole('option', { name: /Lihat semua 240 aset yang cocok/, hidden: true }))
    expect(screen.getByTestId('where')).toHaveTextContent('/aset?search=laptop')
  })

  it('never searches assets for a user without access to them', async () => {
    const requests = renderSearch({ searchAssets: false })
    fireEvent.click(screen.getByRole('button', { name: 'Cari menu' }))
    fireEvent.change(within(palette()).getByRole('combobox', { hidden: true }), { target: { value: 'laptop' } })

    expect(await within(palette()).findByText('Tidak ada yang cocok.')).toBeInTheDocument()
    expect(requests).toHaveLength(0)
  })
})
