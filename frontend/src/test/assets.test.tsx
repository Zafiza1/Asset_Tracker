import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { ModuleBoundary } from '../app/ModuleBoundary'
import { tenantNavigation } from '../app/navigation'
import { CategoryFilter } from '../features/assets/CategoryFilter'
import { BarList, TrendChart } from '../features/dashboard/charts'
import { allowedTypes } from '../features/transactions/api'

describe('module isolation', () => {
  function Broken(): never {
    throw new Error('boom')
  }

  it('keeps an error inside the failing module', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    render(
      <>
        <nav>Menu tetap ada</nav>
        <ModuleBoundary name="Per kategori" compact>
          <Broken />
        </ModuleBoundary>
        <ModuleBoundary name="Per lokasi" compact>
          <p>Widget lain berfungsi</p>
        </ModuleBoundary>
      </>,
    )
    expect(screen.getByRole('alert')).toHaveTextContent('Bagian "Per kategori" mengalami kesalahan')
    expect(screen.getByText('Menu tetap ada')).toBeInTheDocument()
    expect(screen.getByText('Widget lain berfungsi')).toBeInTheDocument()
    vi.restoreAllMocks()
  })
})

describe('tenant navigation', () => {
  const items = tenantNavigation.flatMap((s) => s.items)

  it('offers the asset modules and hides internal generic data from users', () => {
    expect(items.map((i) => i.to)).toEqual(expect.arrayContaining(['/', '/aset', '/transaksi', '/master/kategori']))
    expect(items.some((i) => i.to.includes('katalog') || i.to.includes('definisi'))).toBe(false)
    expect(items.some((i) => /generic/i.test(i.label))).toBe(false)
  })
})

describe('transaction types per asset status', () => {
  it('mirrors the API rules', () => {
    expect(allowedTypes('on_loan')).toEqual(['return', 'note'])
    expect(allowedTypes('available')).toContain('loan')
    expect(allowedTypes('disposed')).not.toContain('transfer')
    expect(allowedTypes('under_maintenance')).not.toContain('loan')
  })
})

describe('CategoryFilter', () => {
  const options = [
    { value: 'a', label: 'Kendaraan (KEND)', name: 'Kendaraan' },
    { value: 'b', label: '  Mobil (MOBIL)', name: 'Mobil' },
  ]

  it('adds and removes categories', () => {
    const onChange = vi.fn()
    const { rerender } = render(<CategoryFilter options={options} selected={[]} onChange={onChange} />)
    fireEvent.click(screen.getByLabelText(/Mobil/))
    expect(onChange).toHaveBeenLastCalledWith(['b'])

    rerender(<CategoryFilter options={options} selected={['a', 'b']} onChange={onChange} />)
    expect(screen.getByText('Kategori (2)')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Hapus filter Kendaraan' }))
    expect(onChange).toHaveBeenLastCalledWith(['b'])
  })
})

describe('dashboard charts', () => {
  it('prints every value in the bar list', () => {
    render(
      <MemoryRouter>
        <BarList data={[{ key: '1', label: 'Perangkat IT', value: 1200, to: '/aset?category_ids=1' }, { key: '2', label: 'Kendaraan', value: 3 }]} />
      </MemoryRouter>,
    )
    expect(screen.getByRole('link', { name: 'Perangkat IT' })).toHaveAttribute('href', '/aset?category_ids=1')
    expect(screen.getByText('1.200')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('shows an empty message instead of zero bars', () => {
    render(<BarList data={[{ key: '1', label: 'X', value: 0 }]} emptyText="Kosong" />)
    expect(screen.getByText('Kosong')).toBeInTheDocument()
  })

  it('offers a table view of the trend', () => {
    render(<TrendChart data={[{ month: '2026-09', added: 4, changes: 7 }, { month: '2026-10', added: 1, changes: 0 }]} />)
    expect(screen.getByRole('img', { name: /per bulan/ })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Tampilkan tabel' }))
    const rows = screen.getAllByRole('row')
    expect(rows).toHaveLength(3)
    expect(rows[1]).toHaveTextContent('4')
    expect(rows[1]).toHaveTextContent('7')
  })
})
