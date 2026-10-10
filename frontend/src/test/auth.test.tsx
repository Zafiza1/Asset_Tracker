import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RequireAuth } from '../app/guards'
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
