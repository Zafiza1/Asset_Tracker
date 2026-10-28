import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { EmailVerificationPage } from '../features/auth/EmailVerificationPage'
import { InvitationPage } from '../features/auth/InvitationPage'
import { AuthProvider } from '../lib/auth/AuthContext'

const TOKEN = 'a'.repeat(64)
const preview = {
  email: 'budi@perusahaan.test',
  organization: { id: 'org-a', code: 'ALPHA', name: 'PT Alpha' },
  invited_by: 'Admin Alpha',
  roles: [{ code: 'SUPERVISOR', name: 'Supervisor' }],
  expires_at: '2026-10-27T10:00:00+00:00',
}
const userOf = (email: string, verified = true) => ({
  id: 'u1', name: 'Budi', email, email_verified_at: verified ? '2026-10-20T00:00:00+00:00' : null,
  user_type: 'tenant', job_title: null, must_change_password: false, last_login_at: null,
})
const profileOf = (user: ReturnType<typeof userOf>) => ({
  data: { user, organization: { id: 'org-b', code: 'BETA', name: 'PT Beta' }, organizations: [{ id: 'org-b', code: 'BETA', name: 'PT Beta' }], permissions: [], data_scope: null },
})
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const apiError = (status: number, code: string, message = 'Ditolak.') => json({ error: { code, message } }, status)

type Call = { url: string; body: Record<string, unknown> | null }
let calls: Call[]

/** A tiny backend: `session` is the logged-in user (null = guest). */
function serve(handlers: { session: ReturnType<typeof userOf> | null; inspect?: () => Response; accept?: () => Response; verify?: () => Response }) {
  let session = handlers.session
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null })
      if (url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (url === '/api/auth/me') return session ? json(profileOf(session)) : apiError(401, 'UNAUTHENTICATED')
      if (url === '/api/auth/login') {
        session = userOf('budi@perusahaan.test')
        return json(profileOf(session))
      }
      if (url === '/api/auth/logout') {
        session = null
        return new Response(null, { status: 204 })
      }
      if (url === '/api/auth/invitation/inspect') return handlers.inspect?.() ?? json({ data: preview })
      if (url === '/api/auth/invitation/accept') return handlers.accept?.() ?? json({ data: { organization: preview.organization } })
      if (url === '/api/auth/invitation/register') {
        session = userOf('budi@perusahaan.test')
        return json({ data: { organization: preview.organization } }, 201)
      }
      if (url.startsWith('/api/auth/email/verify?')) return handlers.verify?.() ?? json({ data: { verified: true } })
      if (url === '/api/auth/email/verification-notification') return json({ data: { verified: false, sent: true } }, 202)
      return apiError(404, 'NOT_FOUND')
    }),
  )
}

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{`${location.pathname}${location.search}${location.hash}`}</output>
}

function renderAt(entry: string) {
  return render(
    <StrictMode>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthProvider>
          <MemoryRouter initialEntries={[entry]}>
            <Routes>
              <Route path="/undangan" element={<InvitationPage />} />
              <Route path="/verifikasi-email" element={<EmailVerificationPage />} />
            </Routes>
            <LocationProbe />
          </MemoryRouter>
        </AuthProvider>
      </QueryClientProvider>
    </StrictMode>,
  )
}

const bodiesOf = (url: string) => calls.filter((c) => c.url === url).map((c) => c.body)

beforeEach(() => {
  calls = []
  localStorage.clear()
  sessionStorage.clear()
})
afterEach(() => vi.unstubAllGlobals())

describe('InvitationPage', () => {
  it('takes the token out of the address bar, keeps it out of storage, and sends it only in the body', async () => {
    serve({ session: null })
    renderAt(`/undangan#token=${TOKEN}`)

    expect(await screen.findByText(/mengundang/)).toHaveTextContent('Admin Alpha mengundang budi@perusahaan.test bergabung dengan PT Alpha (ALPHA).')
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/undangan$/))
    expect(bodiesOf('/api/auth/invitation/inspect')[0]).toEqual({ token: TOKEN })
    expect(calls.some((c) => c.url.includes(TOKEN))).toBe(false)
    expect(JSON.stringify({ ...localStorage })).not.toContain(TOKEN)
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(TOKEN)
  })

  it('lets a guest log in on the page and then accept explicitly', async () => {
    serve({ session: null })
    const user = userEvent.setup()
    renderAt(`/undangan#token=${TOKEN}`)

    await screen.findByRole('tab', { name: 'Saya sudah punya akun' })
    expect(screen.getByRole('tab', { name: 'Saya belum punya akun' })).toBeInTheDocument()
    await user.type(screen.getByLabelText(/^Password/), 'Rahasia-Kuat-2026')
    await user.click(screen.getByRole('button', { name: 'Masuk' }))

    // Logging in alone joins nothing.
    const accept = await screen.findByRole('button', { name: 'Terima undangan' })
    expect(bodiesOf('/api/auth/invitation/accept')).toHaveLength(0)
    await user.click(accept)

    expect(await screen.findByText('Undangan diterima')).toBeInTheDocument()
    expect(bodiesOf('/api/auth/invitation/accept')).toEqual([{ token: TOKEN }])
  })

  it('signs up an address without an account through the invitation', async () => {
    serve({ session: null })
    const user = userEvent.setup()
    renderAt(`/undangan#token=${TOKEN}`)

    await user.click(await screen.findByRole('tab', { name: 'Saya belum punya akun' }))
    await user.type(screen.getByLabelText(/Nama lengkap/), 'Budi')
    await user.type(screen.getByLabelText(/^Password/), 'Rahasia-Kuat-2026')
    await user.type(screen.getByLabelText(/Ulangi password/), 'Rahasia-Kuat-2026')
    await user.click(screen.getByRole('button', { name: 'Daftar dan terima undangan' }))

    expect(await screen.findByText('Undangan diterima')).toBeInTheDocument()
    expect(bodiesOf('/api/auth/invitation/register')).toEqual([{ name: 'Budi', password: 'Rahasia-Kuat-2026', password_confirmation: 'Rahasia-Kuat-2026', token: TOKEN }])
  })

  it('does not offer accepting to another account, and lets it log out', async () => {
    serve({ session: userOf('orang-lain@perusahaan.test') })
    const user = userEvent.setup()
    renderAt(`/undangan#token=${TOKEN}`)

    expect(await screen.findByText('Undangan untuk email lain')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terima undangan' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Keluar dan masuk dengan akun lain' }))
    expect(await screen.findByRole('tab', { name: 'Saya sudah punya akun' })).toBeInTheDocument()
  })

  it('asks an unverified account to verify first', async () => {
    serve({ session: userOf('budi@perusahaan.test', false) })
    renderAt(`/undangan#token=${TOKEN}`)

    expect(await screen.findByText('Verifikasi email terlebih dahulu')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Kirim tautan verifikasi' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terima undangan' })).not.toBeInTheDocument()
  })

  it.each([
    ['INVITATION_EXPIRED', 410, 'Undangan sudah kedaluwarsa'],
    ['INVITATION_REVOKED', 410, 'Undangan sudah dicabut'],
    ['INVITATION_USED', 409, 'Undangan sudah digunakan'],
    ['INVITATION_INVALID', 404, 'Tautan undangan tidak valid'],
  ])('explains %s', async (code, status, title) => {
    serve({ session: null, inspect: () => apiError(status, code) })
    renderAt(`/undangan#token=${TOKEN}`)
    expect(await screen.findByText(title)).toBeInTheDocument()
  })

  it('treats a link without a token as invalid without calling the API', async () => {
    serve({ session: null })
    renderAt('/undangan')
    expect(await screen.findByText('Tautan undangan tidak valid')).toBeInTheDocument()
    expect(bodiesOf('/api/auth/invitation/inspect')).toHaveLength(0)
  })

  it('shows the server message when accepting fails', async () => {
    serve({ session: userOf('budi@perusahaan.test'), accept: () => apiError(410, 'INVITATION_UNAVAILABLE', 'Undangan ini tidak dapat diterima lagi.') })
    const user = userEvent.setup()
    renderAt(`/undangan#token=${TOKEN}`)

    await user.click(await screen.findByRole('button', { name: 'Terima undangan' }))
    expect(await screen.findByText('Undangan ini tidak dapat diterima lagi.')).toBeInTheDocument()
  })
})

describe('EmailVerificationPage', () => {
  const signed = 'expires=1792000000&hash=abc&id=u1&signature=sig'

  it('forwards the signed link unchanged once, then removes it from the address bar', async () => {
    serve({ session: userOf('budi@perusahaan.test', false) })
    renderAt(`/verifikasi-email?${signed}`)

    expect(await screen.findByText('Email terverifikasi')).toBeInTheDocument()
    expect(calls.filter((c) => c.url.startsWith('/api/auth/email/verify')).map((c) => c.url)).toEqual([`/api/auth/email/verify?${signed}`])
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/verifikasi-email$/))
  })

  it('asks a guest to log in before verifying, on the same page', async () => {
    serve({ session: null })
    const user = userEvent.setup()
    renderAt(`/verifikasi-email?${signed}`)

    await screen.findByText(/untuk menyelesaikan verifikasi/)
    expect(calls.some((c) => c.url.startsWith('/api/auth/email/verify'))).toBe(false)
    await user.type(screen.getByLabelText(/^Email/), 'budi@perusahaan.test')
    await user.type(screen.getByLabelText(/^Password/), 'Rahasia-Kuat-2026')
    await user.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByText('Email terverifikasi')).toBeInTheDocument()
  })

  it('offers a new link when the link expired', async () => {
    serve({ session: userOf('budi@perusahaan.test', false), verify: () => apiError(410, 'VERIFICATION_LINK_EXPIRED', 'Tautan verifikasi sudah kedaluwarsa. Minta tautan baru.') })
    const user = userEvent.setup()
    renderAt(`/verifikasi-email?${signed}`)

    expect(await screen.findByText(/sudah kedaluwarsa/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Kirim tautan verifikasi' }))
    expect(await screen.findByText(/Tautan verifikasi dikirim ke budi@perusahaan.test/)).toBeInTheDocument()
  })
})
