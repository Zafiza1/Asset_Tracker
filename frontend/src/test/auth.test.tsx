import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RequireAuth } from '../app/guards'
import { LoginPage } from '../features/auth/LoginPage'
import { SelectOrganizationPage } from '../features/organizations/OrganizationPages'
import { AuthProvider } from '../lib/auth/AuthContext'

afterEach(() => vi.unstubAllGlobals())

describe('AuthProvider', () => {
  it('leaves the loading state and redirects to login when /auth/me returns 401', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ error: { code: 'UNAUTHENTICATED', message: 'Silakan login.' } }), { status: 401 })),
    )
    render(
      <StrictMode>
        <QueryClientProvider client={new QueryClient()}>
          <AuthProvider>
            <MemoryRouter initialEntries={['/']}>
              <Routes>
                <Route path="/login" element={<p>halaman login</p>} />
                <Route element={<RequireAuth />}>
                  <Route path="/" element={<p>beranda</p>} />
                </Route>
              </Routes>
            </MemoryRouter>
          </AuthProvider>
        </QueryClientProvider>
      </StrictMode>,
    )
    expect(await screen.findByText('halaman login')).toBeInTheDocument()
  })
})

describe('Organization selection', () => {
  const user = { id: 'u1', name: 'Pemilik', email: 'pemilik@grup.test', user_type: 'tenant', job_title: null, must_change_password: false, last_login_at: null }
  const organizations = [
    { id: 'o1', code: 'ALPHA', name: 'PT Alpha' },
    { id: 'o2', code: 'BETA', name: 'PT Beta' },
  ]
  const profile = (organization: (typeof organizations)[number] | null) => ({
    data: { user, organization, organizations, permissions: [], data_scope: null },
  })

  it('sends a tenant user without an active organization to the picker and switches with the same session', async () => {
    const calls: { url: string; init?: RequestInit }[] = []
    let active: (typeof organizations)[number] | null = null
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        calls.push({ url, init })
        if (url === '/api/auth/organization') active = organizations[1]
        if (url.startsWith('/api/auth/')) return new Response(JSON.stringify(profile(active)), { status: 200 })
        return new Response(null, { status: 204 })
      }),
    )
    render(
      <QueryClientProvider client={new QueryClient()}>
        <AuthProvider>
          <MemoryRouter initialEntries={['/aset']}>
            <Routes>
              <Route element={<RequireAuth />}>
                <Route path="/pilih-organisasi" element={<SelectOrganizationPage />} />
                <Route path="/" element={<p>beranda</p>} />
                <Route path="/aset" element={<p>daftar aset</p>} />
              </Route>
            </Routes>
          </MemoryRouter>
        </AuthProvider>
      </QueryClientProvider>,
    )

    await userEvent.click(await screen.findByRole('button', { name: /PT Beta/ }))

    expect(await screen.findByText('beranda')).toBeInTheDocument()
    const put = calls.find((c) => c.url === '/api/auth/organization')
    expect(put?.init?.method).toBe('PUT')
    expect(JSON.parse(String(put?.init?.body))).toEqual({ organization_id: 'o2' })
  })

  it('asks an account with several organizations to choose one after login, even when one was used before', async () => {
    const calls: string[] = []
    let loggedIn = false
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        calls.push(url)
        if (url === '/api/auth/me' && !loggedIn) return new Response(JSON.stringify({ error: { code: 'UNAUTHENTICATED', message: 'Silakan login.' } }), { status: 401 })
        if (url === '/api/auth/login') loggedIn = true
        // The server pre-selects the organization used last time.
        return new Response(JSON.stringify(profile(organizations[0])), { status: 200 })
      }),
    )
    render(
      <QueryClientProvider client={new QueryClient()}>
        <AuthProvider>
          <MemoryRouter initialEntries={['/login']}>
            <Routes>
              <Route path="/login" element={<LoginPage />} />
              <Route element={<RequireAuth />}>
                <Route path="/pilih-organisasi" element={<SelectOrganizationPage />} />
                <Route path="/" element={<p>beranda</p>} />
              </Route>
            </Routes>
          </MemoryRouter>
        </AuthProvider>
      </QueryClientProvider>,
    )

    await userEvent.type(await screen.findByLabelText(/Email/), 'pemilik@grup.test')
    await userEvent.type(screen.getByLabelText(/Password/), 'rahasia')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    const alpha = await screen.findByRole('button', { name: /PT Alpha/ })
    expect(alpha).toHaveTextContent('Terakhir digunakan')
    expect(screen.queryByText('beranda')).not.toBeInTheDocument()

    await userEvent.click(alpha)
    expect(await screen.findByText('beranda')).toBeInTheDocument()
    expect(calls).not.toContain('/api/auth/organization')
  })
})
