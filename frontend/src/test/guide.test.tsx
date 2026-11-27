import { render, screen } from '@testing-library/react'
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
    expect(screen.getAllByRole('link', { name: 'Data Aset' })[0]).toHaveAttribute('href', '/aset')
    expect(screen.queryByRole('link', { name: 'Modul Barcode' })).not.toBeInTheDocument()
    expect(screen.getByText('Modul Barcode')).toBeInTheDocument()
    first.unmount()

    renderGuide(['settings.manage'])
    expect(screen.getByRole('link', { name: 'Modul Barcode' })).toHaveAttribute('href', '/modul/barcode')
  })

  it('explains how cylinders are sent and picked up', () => {
    renderGuide([])
    expect(screen.getByRole('heading', { name: /Kirim & jemput tabung/ })).toBeInTheDocument()
    expect(screen.queryByText(/lisensi/i)).not.toBeInTheDocument()
  })

  it('prepares Master Data before inviting, since an invitation picks an employee', () => {
    renderGuide([])
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)
    expect(headings.indexOf('Siapkan Master Data')).toBeLessThan(headings.indexOf('Undang tim & atur akses'))
    expect(screen.getAllByText(/Divisi/).length).toBeGreaterThan(0)
    expect(screen.getAllByText('Kendaraan').length).toBeGreaterThan(0)
    // Vehicles are Master Data in this edition, not a category of assets.
    expect(screen.queryByText('Aset Kendaraan')).not.toBeInTheDocument()
  })

  it('warns about a cylinder that is not in the transaction and one scanned twice', () => {
    renderGuide([])
    expect(screen.getAllByText(/Tabung tidak sesuai/).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/Tabung sudah di-scan/).length).toBeGreaterThan(0)
  })
})
