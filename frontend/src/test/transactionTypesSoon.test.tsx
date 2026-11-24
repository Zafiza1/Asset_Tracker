import { render, screen } from '@testing-library/react'
import { createMemoryRouter, matchRoutes, RouterProvider, type RouteObject } from 'react-router'
import { describe, expect, it } from 'vitest'
import { router } from '../app/router'
import { TransactionTypesSoonPage } from '../features/transactionTypes/TransactionTypesSoonPage'
import { AuthContext, type AuthState } from '../lib/auth/context'
import type { Profile } from '../lib/api/types'

const auth = { profile: { user: { id: 'u1', user_type: 'tenant' }, organization: { id: 'o1' }, permissions: [], modules: [] } as unknown as Profile, isLoading: false, can: () => true } as unknown as AuthState

/** The element the app's router shows at a path, without the layout around it. */
const pageAt = (path: string) => matchRoutes(router.routes as RouteObject[], path)?.at(-1)?.route.element

describe('Jenis Transaksi while it is closed', () => {
  it.each(['', '/baru', '/baru/kosong', '/t1', '/t1/alur', '/t1/rules', '/t1/panduan', '/t1/cara-kerja'])('shows "Segera hadir" at /transaksi/jenis%s', async (sub) => {
    const path = `/transaksi/jenis${sub}`
    const page = createMemoryRouter([{ path: '*', element: <AuthContext.Provider value={auth}>{pageAt(path)}</AuthContext.Provider> }], { initialEntries: [path] })
    render(<RouterProvider router={page} />)
    expect(await screen.findByText('Segera hadir')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: /Jenis Transaksi/ })).toBeInTheDocument()
  })

  it('leads on to recording transactions', () => {
    const page = createMemoryRouter([{ path: '/', element: <TransactionTypesSoonPage /> }])
    render(<RouterProvider router={page} />)
    expect(screen.getByRole('link', { name: 'Buka Transaksi' })).toHaveAttribute('href', '/transaksi')
  })
})
