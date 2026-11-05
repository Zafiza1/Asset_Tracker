import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { GuidePage } from '../features/guide/GuidePage'
import type { Profile } from '../lib/api/types'
import { AuthContext, type AuthState } from '../lib/auth/context'

function renderGuide(permissions: string[]) {
  const state: AuthState = {
    profile: { permissions } as unknown as Profile,
    isLoading: false,
    can: (p) => permissions.includes(p),
    login: vi.fn(),
    signUp: vi.fn(),
    logout: vi.fn(),
    setProfile: vi.fn(),
    switchOrganization: vi.fn(),
    createOrganization: vi.fn(),
  }
  return render(
    <AuthContext.Provider value={state}>
      <MemoryRouter>
        <GuidePage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('GuidePage', () => {
  it('links every "next step" to a section that exists', () => {
    const { container } = renderGuide([])
    const targets = [...container.querySelectorAll('a[href^="#"]')].map((a) => a.getAttribute('href')!.slice(1))
    expect(targets.length).toBeGreaterThan(0)
    for (const id of targets) expect(container.querySelector(`[id="${id}"]`), id).not.toBeNull()
  })

  it('tells approvers and other users how approval applies to them', () => {
    const first = renderGuide(['approval.manage'])
    expect(screen.getByText('Anda termasuk penyetuju')).toBeInTheDocument()
    first.unmount()

    renderGuide([])
    expect(screen.getByText('Perubahan Anda perlu disetujui')).toBeInTheDocument()
  })

  it('only links to pages the role can open', () => {
    const first = renderGuide(['asset.view'])
    expect(screen.getAllByRole('link', { name: 'Pendataan Aset' })[0]).toHaveAttribute('href', '/aset')
    expect(screen.queryByRole('link', { name: 'Modul Barcode' })).not.toBeInTheDocument()
    expect(screen.getByText('Modul Barcode')).toBeInTheDocument()
    first.unmount()

    renderGuide(['settings.manage'])
    expect(screen.getByRole('link', { name: 'Modul Barcode' })).toHaveAttribute('href', '/modul/barcode')
  })

  it('shows which transactions each asset status allows', () => {
    renderGuide([])
    const row = screen.getByRole('rowheader', { name: 'Dipinjamkan' }).closest('tr')!
    // Columns: Pemindahan lokasi, Perubahan status, Peminjaman, Pengembalian, Aktivitas lain.
    expect(within(row).getAllByRole('cell').map((c) => c.textContent)).toEqual(['Tidak', 'Tidak', 'Tidak', 'Ya', 'Ya'])
  })
})
