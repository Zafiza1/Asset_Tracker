import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PlatformOrganizationDetailPage } from '../features/platform/PlatformOrganizationDetailPage'
import { PlatformOrganizationsPage } from '../features/platform/PlatformOrganizationsPage'
import { api } from '../lib/api/client'
import type { AdminOnboarding, EmailDelivery, Organization, OrganizationInvitation } from '../lib/api/types'

vi.mock('../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/client')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn() } }
})
vi.mock('../lib/auth/context', () => ({ useAuth: () => ({ can: () => true }) }))

const organization: Organization = {
  id: 'org-g', code: 'GAMMA', name: 'PT Gamma', legal_name: null, status: 'active', timezone: 'Asia/Jakarta', locale: 'id',
  currency: 'IDR', email: null, phone: null, tax_id: null, address: null, created_at: '2026-10-24T00:00:00Z', updated_at: '2026-10-24T00:00:00Z',
}

const invitation = (overrides: Partial<OrganizationInvitation> = {}): OrganizationInvitation => ({
  id: 'inv-1', organization_id: 'org-g', email: 'admin@gamma.test', status: 'pending', scopes: [{ scope_type: 'organization', ref_id: null }],
  employee_number: null, job_title: null, home_department_id: null, expires_at: '2026-10-27T00:00:00Z', last_sent_at: null, send_count: 1,
  accepted_at: null, revoked_at: null, created_at: '2026-10-24T00:00:00Z', license_code_id: null, ...overrides,
})

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/platform/organisasi" element={<PlatformOrganizationsPage />} />
          <Route path="/platform/organisasi/:id" element={<PlatformOrganizationDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function mockDetail(onboarding: AdminOnboarding, delivery: EmailDelivery) {
  vi.mocked(api.get).mockImplementation(async (url: string) =>
    url.endsWith('/admin-invitation') ? { data: onboarding, meta: { email_delivery: delivery } } : { data: organization },
  )
}

describe('Platform: create organization', () => {
  beforeEach(() => vi.clearAllMocks())

  it('asks only for the administrator email and explains the invitation', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
    vi.mocked(api.post).mockResolvedValue({ data: organization })
    renderAt('/platform/organisasi')

    fireEvent.click(await screen.findByRole('button', { name: /Organisasi baru/ }))
    expect(screen.queryByLabelText(/Password/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/^Nama$/)).not.toBeInTheDocument()
    expect(screen.getByText(/menjadi Admin organisasi setelah menerima undangan/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText(/^Kode/), { target: { value: 'GAMMA' } })
    fireEvent.change(screen.getByLabelText(/^Nama organisasi/), { target: { value: 'PT Gamma' } })
    fireEvent.change(screen.getByLabelText(/^Email administrator/), { target: { value: 'admin@gamma.test' } })
    // jsdom has no showModal(), so the dialog's content counts as hidden for role queries.
    fireEvent.click(screen.getByRole('button', { name: /Buat & kirim undangan/, hidden: true }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/platform/organizations', { code: 'GAMMA', name: 'PT Gamma', admin: { email: 'admin@gamma.test' } }),
    )
  })
})

describe('Platform: first administrator onboarding', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows a pending invitation and does not claim delivery when emails only go to the log', async () => {
    mockDetail({ has_members: false, invitation: invitation() }, 'log')
    vi.mocked(api.post).mockResolvedValue({ data: invitation({ send_count: 2 }), meta: { email_delivery: 'log' } })
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    renderAt('/platform/organisasi/org-g')

    expect(await screen.findByText('admin@gamma.test')).toBeInTheDocument()
    expect(screen.getByText('Menunggu')).toBeInTheDocument()
    expect(screen.getByText(/hanya ditulis ke log server dan tidak sampai ke penerima/)).toBeInTheDocument()
    expect(screen.getByText(/Belum ada yang dapat mengakses organisasi ini/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/platform/organizations/org-g/admin-invitation/resend', undefined))
    expect(await screen.findByText(/dibuat, tetapi email hanya ditulis ke log server/)).toBeInTheDocument()
    expect(screen.queryByText(/Undangan dikirim ke/)).not.toBeInTheDocument()
    expect(setItem).not.toHaveBeenCalled()
  })

  it('invites another address with a real mailer', async () => {
    mockDetail({ has_members: false, invitation: invitation({ status: 'revoked' }) }, 'mail')
    vi.mocked(api.post).mockResolvedValue({ data: invitation({ email: 'baru@gamma.test' }), meta: { email_delivery: 'mail' } })
    renderAt('/platform/organisasi/org-g')

    expect(await screen.findByText('Dicabut')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kirim ulang' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Undang email lain' }))
    fireEvent.change(screen.getByLabelText(/^Email administrator/), { target: { value: 'baru@gamma.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kirim undangan' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/platform/organizations/org-g/admin-invitation', { email: 'baru@gamma.test' }))
    expect(await screen.findByText('Undangan dikirim ke baru@gamma.test.')).toBeInTheDocument()
  })

  it('offers no invitation actions once the administrator has joined', async () => {
    mockDetail({ has_members: true, invitation: invitation({ status: 'accepted', accepted_at: '2026-10-25T00:00:00Z' }) }, 'log')
    renderAt('/platform/organisasi/org-g')

    expect(await screen.findByText(/Administrator sudah bergabung/)).toBeInTheDocument()
    for (const name of ['Kirim ulang', 'Cabut', 'Undang email lain', 'Undang administrator']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    expect(screen.queryByText(/hanya ditulis ke log server/)).not.toBeInTheDocument()
  })
})
