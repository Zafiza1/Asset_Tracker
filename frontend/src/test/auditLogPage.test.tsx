import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuditLogPage } from '../features/audit/AuditLogPage'
import { auditCategoryOf, auditCodesMatching } from '../features/audit/auditMeta'
import type { AuditLog } from '../lib/api/types'
import { auditActionLabel } from '../lib/format'

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const dialogTitled = async (title: string) => (await screen.findByText(title, { selector: 'h2' })).closest('dialog') as HTMLElement

const log = (id: string, extra: Partial<AuditLog> = {}): AuditLog => ({
  id,
  organization_id: 'o-1',
  actor_type: 'user',
  actor: { id: 'u-budi', name: 'Budi Santoso', email: 'budi@contoh.id' },
  action: 'asset.updated',
  entity_type: 'Asset',
  entity_id: 'a-1',
  entity_label: { code: 'AST-0001', name: 'Laptop Kantor' },
  before: { name: 'Laptop', status: 'active' },
  after: { name: 'Laptop Kantor', status: 'active' },
  metadata: null,
  ip: '10.10.20.7',
  request_id: 'req-1',
  created_at: '2026-11-12T03:15:00Z',
  ...extra,
})

function renderPage(rows: AuditLog[] = [log('1'), log('2', { action: 'asset.image_updated', before: null, after: null, ip: null })]) {
  const fetchMock = vi.fn(async (url: string) => {
    const u = String(url)
    if (u.startsWith('/api/audit-logs/actors')) return new Response(JSON.stringify({ data: [{ id: 'u-budi', name: 'Budi Santoso', email: 'budi@contoh.id' }] }), { status: 200 })
    if (u.startsWith('/api/audit-logs')) return new Response(JSON.stringify({ data: rows, meta: { current_page: 1, last_page: 1, per_page: 10, total: rows.length, from: 1, to: rows.length } }), { status: 200 })
    return new Response('{}', { status: 404 })
  })
  vi.stubGlobal('fetch', fetchMock)
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={['/audit']}>
        <AuditLogPage endpoint="/audit-logs" title="Audit Trail" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  const listCalls = () => fetchMock.mock.calls.map(([url]) => decodeURIComponent(String(url))).filter((u) => u.startsWith('/api/audit-logs?'))
  return { listCalls }
}

describe('Audit trail labels', () => {
  it('never shows a raw code, even for actions without a written label', () => {
    expect(auditActionLabel('asset.image_updated')).toBe('Mengganti foto aset')
    expect(auditActionLabel('asset.warranty_updated')).toBe('Mengubah aset (warranty)')
  })

  it('classifies actions and finds codes by their readable label', () => {
    expect(auditCategoryOf('asset.created')).toBe('create')
    expect(auditCategoryOf('location.archived')).toBe('delete')
    expect(auditCategoryOf('auth.login')).toBe('access')
    expect(auditCategoryOf('user.roles_changed')).toBe('update')
    expect(auditCodesMatching('foto')).toEqual(['asset.image_updated', 'asset.image_removed'])
  })
})

describe('Audit trail page', () => {
  it('shows actor name and email, readable activity with a category badge, module and object', async () => {
    renderPage()
    expect(await screen.findAllByText('Budi Santoso')).toHaveLength(2)
    expect(screen.getAllByText('budi@contoh.id')).toHaveLength(2)
    expect(screen.getByText('Mengganti foto aset')).toBeInTheDocument()
    expect(screen.queryByText('asset.image_updated')).not.toBeInTheDocument()
    expect(screen.getAllByText('Ubah').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Aset').length).toBeGreaterThan(0)
    expect(screen.getAllByText('AST-0001')).toHaveLength(2)
  })

  it('opens a detail with before/after values, full time and IP address', async () => {
    renderPage()
    fireEvent.click((await screen.findAllByRole('button', { name: 'Lihat detail: Mengubah data aset' }))[0])
    const dialog = await dialogTitled('Detail aktivitas')
    expect(within(dialog).getByText('budi@contoh.id')).toBeInTheDocument()
    expect(within(dialog).getByText('10.10.20.7')).toBeInTheDocument()
    expect(within(dialog).getByText(/2026/)).toHaveTextContent(/November/)
    expect(within(dialog).getByText('Sebelum')).toBeInTheDocument()
    expect(within(dialog).getByText('Laptop')).toBeInTheDocument()
    expect(within(dialog).getAllByText('Laptop Kantor').length).toBeGreaterThan(0)
  })

  it('searches with matching action codes and filters by actor, module and category', async () => {
    const { listCalls } = renderPage()
    fireEvent.change(await screen.findByLabelText('Cari di audit trail'), { target: { value: 'foto' } })
    fireEvent.click(screen.getByRole('button', { name: 'Cari' }))
    await waitFor(() => expect(listCalls().some((u) => u.includes('search=foto') && u.includes('search_actions=asset.image_updated,asset.image_removed'))).toBe(true))

    fireEvent.click(screen.getByRole('button', { name: /Filter lanjutan/ }))
    await waitFor(() => expect(screen.getByRole('option', { name: 'Budi Santoso (budi@contoh.id)' })).toBeInTheDocument())
    fireEvent.change(screen.getByLabelText('Filter pelaku'), { target: { value: 'u-budi' } })
    fireEvent.change(screen.getByLabelText('Filter modul'), { target: { value: 'asset,asset_transaction' } })
    fireEvent.change(screen.getByLabelText('Filter kategori aktivitas'), { target: { value: 'delete' } })
    fireEvent.click(screen.getByRole('button', { name: 'Terapkan filter' }))
    await waitFor(() =>
      expect(listCalls().some((u) => u.includes('actor_user_id=u-budi') && u.includes('module=asset,asset_transaction') && u.includes('actions=') && u.includes('location.archived'))).toBe(true),
    )
    expect(screen.getByText('Pelaku: Budi Santoso')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reset filter' })).toBeInTheDocument()
  })

  it('keeps "Terapkan filter" disabled while the date range is inverted', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /Filter lanjutan/ }))
    const [from, to] = screen.getAllByDisplayValue('')
      .filter((el) => el.getAttribute('type') === 'date')
    fireEvent.change(from, { target: { value: '2026-11-12' } })
    fireEvent.change(to, { target: { value: '2026-11-01' } })
    expect(screen.getByRole('alert')).toHaveTextContent('tidak boleh setelah')
    expect(screen.getByRole('button', { name: 'Terapkan filter' })).toBeDisabled()
  })

  it('offers rows per page 10, 25, 50 and 100', async () => {
    const { listCalls } = renderPage()
    const select = await screen.findByLabelText('Baris per halaman')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['10', '25', '50', '100'])
    fireEvent.change(select, { target: { value: '50' } })
    await waitFor(() => expect(listCalls().some((u) => u.includes('per_page=50'))).toBe(true))
  })
})
