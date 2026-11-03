import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createEvent, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { CategoryAttributesModal } from '../features/master/CategoryAttributes'
import type { CategoryAttribute, TenantCategory } from '../lib/api/types'

afterEach(() => vi.unstubAllGlobals())

const category = { id: 'mobil', code: 'MOBIL', name: 'Mobil', status: 'active', depth: 1 } as TenantCategory
const field = (id: string, label: string, category_id: string, extra: Partial<CategoryAttribute> = {}): CategoryAttribute => ({
  id, key: id, label, data_type: 'string', is_required: false, options: null, category_id, sort_order: 0, status: 'active', is_default: false, section: null, ...extra,
})
const kendaraan = { id: 'kendaraan', code: 'KEND', name: 'Kendaraan' }
const fields = [
  field('merek', 'Merek', 'kendaraan', { category: kendaraan, is_default: true, section: 'basic_information' }),
  field('nomor_polisi', 'Nomor Polisi', 'kendaraan', { category: kendaraan }),
  field('warna', 'Warna', 'mobil'),
  field('jumlah_kursi', 'Jumlah Kursi', 'mobil', { data_type: 'integer' }),
]

function renderModal(
  canUpdate: boolean,
  putResponse: () => Response = () => new Response(JSON.stringify({ data: fields }), { status: 200 }),
  of: TenantCategory = category,
  listed: CategoryAttribute[] = fields,
) {
  const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
    init?.method === 'PUT' ? putResponse() : new Response(JSON.stringify({ data: listed }), { status: 200 }),
  )
  vi.stubGlobal('fetch', fetchMock)
  const onClose = vi.fn()
  render(
    <QueryClientProvider client={new QueryClient()}>
      <CategoryAttributesModal category={of} canUpdate={canUpdate} onClose={onClose} />
    </QueryClientProvider>,
  )
  const putBody = () => JSON.parse(String(fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')?.[1]?.body))
  return { onClose, putBody }
}

describe('CategoryAttributesModal', () => {
  it('edits, adds, and removes several attributes, then saves them in one request', async () => {
    const { onClose, putBody } = renderModal(true)
    expect(await screen.findByDisplayValue('Warna')).toBeInTheDocument()
    // Only the category's own fields are rows; the parent's fields (defaults included) are listed read-only.
    expect(screen.queryByDisplayValue('Nomor Polisi')).not.toBeInTheDocument()
    expect(screen.getByText(/Atribut dari kategori induk/)).toBeInTheDocument()
    expect(screen.getByText('Merek')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Hapus atribut 1'))
    expect(screen.getByText('1 atribut kustom akan dihapus saat disimpan.')).toBeInTheDocument()

    fireEvent.click(screen.getByText('Tambah atribut kustom'))
    fireEvent.click(screen.getByText('Tambah atribut kustom'))
    fireEvent.change(screen.getByLabelText(/Nama atribut 2/), { target: { value: 'Bahan Bakar' } })
    fireEvent.change(screen.getByLabelText('Tipe isian 2'), { target: { value: 'enum' } })
    fireEvent.change(screen.getByLabelText('Pilihan 1'), { target: { value: 'Bensin' } })
    // Row 3 stays empty and is not sent.

    fireEvent.click(screen.getByText('Simpan'))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
    expect(putBody()).toEqual({
      attributes: [
        { id: 'jumlah_kursi', label: 'Jumlah Kursi', data_type: 'integer', is_required: false, options: null, section: null },
        { label: 'Bahan Bakar', data_type: 'enum', is_required: false, options: [{ label: 'Bensin' }], section: null },
      ],
    })
  })

  it('lets default fields of a top-level category be moved but not edited or removed', async () => {
    const top = { ...category, id: 'kendaraan', name: 'Kendaraan', depth: 0 } as TenantCategory
    const own = [
      field('merek', 'Merek', 'kendaraan', { is_default: true, section: 'basic_information' }),
      field('nomor_polisi', 'Nomor Polisi', 'kendaraan'),
    ]
    const { onClose, putBody } = renderModal(true, undefined, top, own)
    expect(await screen.findByDisplayValue('Nomor Polisi')).toBeInTheDocument()
    expect(screen.queryByDisplayValue('Merek')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Hapus atribut 1')).not.toBeInTheDocument()

    // Drag the default row by its grip below the custom row (jsdom rects are zero-sized, so any clientY > 0 is "below the middle").
    const grip = screen.getByLabelText('Ubah urutan atribut 1')
    fireEvent.pointerDown(grip)
    const dragged = grip.closest('[draggable="true"]')!
    const target = screen.getByLabelText('Ubah urutan atribut 2').closest('[draggable]')!
    fireEvent.dragStart(dragged, { dataTransfer: { setData: vi.fn(), effectAllowed: '' } })
    // jsdom has no DragEvent, so the pointer position is set on the event by hand.
    const over = createEvent.dragOver(target)
    Object.defineProperty(over, 'clientY', { value: 10 })
    fireEvent(target, over)
    fireEvent.drop(target)
    fireEvent.dragEnd(dragged)
    fireEvent.click(screen.getByText('Simpan'))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
    expect(putBody().attributes.map((a: { id: string }) => a.id)).toEqual(['nomor_polisi', 'merek'])
  })

  it('moves a row with the arrow keys on its grip', async () => {
    const { onClose, putBody } = renderModal(true)
    expect(await screen.findByDisplayValue('Warna')).toBeInTheDocument()
    fireEvent.keyDown(screen.getByLabelText('Ubah urutan atribut 2'), { key: 'ArrowUp' })
    fireEvent.keyDown(screen.getByLabelText('Ubah urutan atribut 1'), { key: 'ArrowUp' }) // already first: no-op
    fireEvent.click(screen.getByText('Simpan'))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
    expect(putBody().attributes.map((a: { id: string }) => a.id)).toEqual(['jumlah_kursi', 'warna'])
  })

  it('shows server errors on the row they belong to', async () => {
    const error = { error: { code: 'VALIDATION_FAILED', message: 'Data yang dikirim tidak valid.', details: { fields: { 'attributes.1.label': ['Nama atribut ganda dalam daftar ini.'] } } } }
    const { onClose } = renderModal(true, () => new Response(JSON.stringify(error), { status: 422 }))
    expect(await screen.findByDisplayValue('Warna')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Simpan'))
    expect(await screen.findByText('Nama atribut ganda dalam daftar ini.')).toBeInTheDocument()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('is read-only without update permission', async () => {
    renderModal(false)
    expect(await screen.findByText('Warna')).toBeInTheDocument()
    expect(screen.queryByText('Tambah atribut')).not.toBeInTheDocument()
    expect(screen.queryByText('Simpan')).not.toBeInTheDocument()
  })
})
