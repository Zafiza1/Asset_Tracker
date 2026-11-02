import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { ModuleBoundary } from '../app/ModuleBoundary'
import { tenantNavigation } from '../app/navigation'
import { AssetImagePicker } from '../features/assets/AssetImagePicker'
import { CategoryFilter } from '../features/assets/CategoryFilter'
import { BarList, ColumnChart, DonutChart, Gauge, MeterList, StackedBar, TrendChart } from '../features/dashboard/charts'
import { AttributeFields } from '../features/master/CategoryAttributes'
import { attributeRows } from '../features/master/categoryAttributeData'
import { allowedTypes, ineligibleReason } from '../features/transactions/api'
import type { CategoryAttribute } from '../lib/api/types'

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

  it('tells why an asset cannot join a multi-asset transaction', () => {
    const asset = { status: 'available' as const, location_id: 'loc-1' }
    expect(ineligibleReason(asset, 'loan')).toBeNull()
    expect(ineligibleReason({ ...asset, status: 'on_loan' }, 'transfer')).toMatch(/Dipinjam|status/i)
    expect(ineligibleReason(asset, 'transfer', { to_location_id: 'loc-1' })).toBe('Sudah di lokasi tujuan')
    expect(ineligibleReason(asset, 'transfer', { to_location_id: 'loc-2' })).toBeNull()
    expect(ineligibleReason(asset, 'status_change', { to_status: 'available' })).toBe('Status sudah sama')
  })
})

describe('CategoryFilter', () => {
  const options = [
    { value: 'a', label: 'Kendaraan (KEND)' },
    { value: 'b', label: '  Mobil (MOBIL)' },
  ]

  it('adds and removes categories', () => {
    const onChange = vi.fn()
    const { rerender } = render(<CategoryFilter options={options} selected={[]} onChange={onChange} />)
    fireEvent.click(screen.getByLabelText(/Mobil/))
    expect(onChange).toHaveBeenLastCalledWith(['b'])

    rerender(<CategoryFilter options={options} selected={['a', 'b']} onChange={onChange} />)
    expect(screen.getByText('Kategori (2)')).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText(/Kendaraan/))
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

  it('formats bar values with the given formatter', () => {
    render(<BarList unit="" format={(v) => `Rp${v}`} data={[{ key: '1', label: 'Perangkat IT', value: 500 }]} />)
    expect(screen.getByText('Rp500')).toBeInTheDocument()
  })

  it('prints the share of each meter as a percentage', () => {
    render(<MeterList data={[{ key: 'a', label: 'Lokasi', part: 3, total: 4 }, { key: 'b', label: 'Foto aset', part: 0, total: 0 }]} />)
    expect(screen.getByText('75%')).toBeInTheDocument()
    expect(screen.getByRole('meter', { name: 'Lokasi' })).toHaveAttribute('aria-valuenow', '75')
    expect(screen.getByText('0%')).toBeInTheDocument()
  })

  it('folds rows past the limit into "Lainnya"', () => {
    render(<BarList limit={2} data={[{ key: 'a', label: 'A', value: 5 }, { key: 'b', label: 'B', value: 3 }, { key: 'c', label: 'C', value: 2 }]} />)
    expect(screen.getByText('Lainnya (2)')).toBeInTheDocument()
    expect(screen.getAllByText('5')).toHaveLength(2) // A, and Lainnya = 3 + 2
    expect(screen.queryByText('B')).not.toBeInTheDocument()
  })

  it('prints every donut slice with its share and shows the hovered one in the center', () => {
    const { container } = render(
      <MemoryRouter>
        <DonutChart totalLabel="aset aktif" data={[{ key: 'a', label: 'Tersedia', value: 3, color: '#000', to: '/aset?status=available' }, { key: 'b', label: 'Digunakan', value: 1, color: '#111' }]} />
      </MemoryRouter>,
    )
    expect(screen.getByRole('link', { name: 'Tersedia' })).toHaveAttribute('href', '/aset?status=available')
    expect(screen.getByText('75%')).toBeInTheDocument()
    expect(screen.getByText('4')).toBeInTheDocument()
    fireEvent.mouseEnter(container.querySelectorAll('circle')[2])
    expect(screen.getByText('Digunakan', { selector: 'p' })).toBeInTheDocument()
  })

  it('shows an empty message for a donut without data', () => {
    render(<DonutChart totalLabel="aset" emptyText="Kosong" data={[{ key: 'a', label: 'A', value: 0, color: '#000' }]} />)
    expect(screen.getByText('Kosong')).toBeInTheDocument()
  })

  it('reports the gauge value as a meter', () => {
    render(<Gauge value={67.9} label="Tingkat pemanfaatan" />)
    expect(screen.getByRole('meter', { name: 'Tingkat pemanfaatan' })).toHaveAttribute('aria-valuenow', '68')
    expect(screen.getByText('67,9%')).toBeInTheDocument()
  })

  it('labels every part of the stacked bar and every column', () => {
    render(<StackedBar data={[{ key: 'o', label: 'Terlambat', value: 2, color: '#d03b3b' }, { key: 'k', label: 'Belum jatuh tempo', value: 0, color: '#0ca30c' }]} />)
    expect(screen.getByText('Terlambat')).toBeInTheDocument()
    expect(screen.getByText('Belum jatuh tempo')).toBeInTheDocument()
    render(<ColumnChart label="Umur aset" data={[{ key: 'a', label: '< 1 tahun', value: 46 }, { key: 'b', label: '1–3 tahun', value: 88 }]} />)
    expect(screen.getByRole('img', { name: /Umur aset: < 1 tahun 46, 1–3 tahun 88/ })).toBeInTheDocument()
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

describe('category attributes', () => {
  const field = (key: string, label: string, data_type: CategoryAttribute['data_type'], extra: Partial<CategoryAttribute> = {}): CategoryAttribute => ({
    id: key, key, label, data_type, is_required: false, options: null, category_id: 'c', sort_order: 0, status: 'active', is_default: false, section: null, ...extra,
  })
  const fields = [
    field('nomor_polisi', 'Nomor Polisi', 'string', { is_required: true }),
    field('bahan_bakar', 'Bahan Bakar', 'enum', { options: [{ value: 'BENSIN', label: 'Bensin' }, { value: 'SOLAR', label: 'Solar' }] }),
    field('ber_ac', 'Ber-AC', 'boolean'),
  ]

  it('renders one input per field of the category and reports changes by key', () => {
    const onChange = vi.fn()
    render(<AttributeFields fields={fields} values={{ bahan_bakar: 'SOLAR' }} errors={{ nomor_polisi: 'Wajib diisi.' }} onChange={onChange} />)
    expect(screen.getByText('Wajib diisi.')).toBeInTheDocument()
    expect(screen.getByLabelText('Bahan Bakar')).toHaveValue('SOLAR')
    fireEvent.change(screen.getByLabelText(/Nomor Polisi/), { target: { value: 'B 1234 XY' } })
    expect(onChange).toHaveBeenLastCalledWith('nomor_polisi', 'B 1234 XY')
  })

  it('renders nothing for a category without fields', () => {
    const { container } = render(<AttributeFields fields={[]} values={{}} errors={{}} onChange={vi.fn()} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('shows values with their labels on the detail page', () => {
    expect(attributeRows(fields, { bahan_bakar: 'BENSIN', ber_ac: false }).map((r) => r.value)).toEqual(['—', 'Bensin', 'Tidak'])
  })
})

describe('AssetImagePicker', () => {
  it('accepts JPG/PNG/WEBP up to 5 MB and marks an existing photo for removal', () => {
    const onChange = vi.fn()
    const { rerender } = render(<AssetImagePicker currentUrl="/api/assets/1/image?v=x" value={undefined} onChange={onChange} />)
    const input = screen.getByLabelText('Pilih gambar aset')

    fireEvent.change(input, { target: { files: [new File(['<svg/>'], 'x.svg', { type: 'image/svg+xml' })] } })
    expect(screen.getByRole('alert')).toHaveTextContent('JPG, PNG, atau WEBP')
    const big = new File(['x'], 'big.jpg', { type: 'image/jpeg' })
    Object.defineProperty(big, 'size', { value: 6 * 1024 * 1024 })
    fireEvent.change(input, { target: { files: [big] } })
    expect(screen.getByRole('alert')).toHaveTextContent('maksimal 5 MB')
    expect(onChange).not.toHaveBeenCalled()

    const photo = new File(['x'], 'a.png', { type: 'image/png' })
    fireEvent.change(input, { target: { files: [photo] } })
    expect(onChange).toHaveBeenLastCalledWith(photo)

    fireEvent.click(screen.getByRole('button', { name: /Hapus/ }))
    expect(onChange).toHaveBeenLastCalledWith(null)
    rerender(<AssetImagePicker currentUrl="/api/assets/1/image?v=x" value={null} onChange={onChange} />)
    expect(screen.queryByAltText('Gambar aset')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Unggah gambar/ })).toBeInTheDocument()
  })
})
