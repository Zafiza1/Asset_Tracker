// jsdom has no showModal(), so the dialog's content counts as hidden for role queries.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ImportModal } from '../components/ui/ImportModal'
import { api, ApiError } from '../lib/api/client'

vi.mock('../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api/client')>()
  return { ...actual, api: { ...actual.api, upload: vi.fn() } }
})

function renderEdit() {
  const onClose = vi.fn()
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ImportModal open mode="edit" title="Multiple Edit Lokasi (Excel)" endpoint="/locations/edit-sheet"
        templateEndpoint="/locations/edit-sheet" templateFilename="edit_lokasi.xlsx" scopeNote="semua lokasi" onClose={onClose} />
    </QueryClientProvider>,
  )
  const file = new File(['x'], 'edit_lokasi.xlsx')
  fireEvent.change(document.querySelector('input[type=file]')!, { target: { files: [file] } })
  return { onClose }
}

describe('ImportModal in edit mode', () => {
  it('uploads the edited sheet and reports how many rows changed', async () => {
    vi.mocked(api.upload).mockResolvedValue({ data: { updated: 3 } })
    const { onClose } = renderEdit()
    expect(screen.getByRole('button', { name: /Download Data/, hidden: true })).toBeInTheDocument()
    expect(screen.getByText(/semua lokasi/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Simpan Perubahan/, hidden: true }))

    expect(await screen.findByText('3 data berhasil diubah.')).toBeInTheDocument()
    expect(vi.mocked(api.upload).mock.calls[0][0]).toBe('/locations/edit-sheet')
    expect(onClose).not.toHaveBeenCalled()
  })

  it('says so when nothing changed', async () => {
    vi.mocked(api.upload).mockResolvedValue({ data: { updated: 0 } })
    renderEdit()
    fireEvent.click(screen.getByRole('button', { name: /Simpan Perubahan/, hidden: true }))
    expect(await screen.findByText('Tidak ada perubahan yang perlu disimpan.')).toBeInTheDocument()
  })

  it('lists the rejected rows', async () => {
    vi.mocked(api.upload).mockRejectedValue(new ApiError(422, 'IMPORT_FAILED', 'Tidak ada data yang disimpan karena 1 baris bermasalah.',
      { errors: ["Baris 3: Lokasi dengan kode 'X' tidak ditemukan atau sudah diarsipkan."] }))
    renderEdit()
    fireEvent.click(screen.getByRole('button', { name: /Simpan Perubahan/, hidden: true }))
    await waitFor(() => expect(screen.getByText(/Baris 3: Lokasi dengan kode 'X'/)).toBeInTheDocument())
  })
})

describe('ImportModal keeps Import and Multiple Edit apart', () => {
  const pick = (name: string) =>
    fireEvent.change(document.querySelector('input[type=file]')!, { target: { files: [new File(['x'], name)] } })

  it('labels the import dialog and warns about an edit file', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ImportModal open title="Import Aset" endpoint="/assets/import" templateEndpoint="/assets/template"
          templateFilename="template_aset.xlsx" onClose={vi.fn()} />
      </QueryClientProvider>,
    )
    expect(screen.getByText(/Mode Import — menambah data baru/)).toBeInTheDocument()
    pick('edit_aset.xlsx')
    expect(screen.getByText(/Nama file ini seperti file Multiple Edit/)).toBeInTheDocument()
    pick('template_aset.xlsx')
    expect(screen.queryByText(/Nama file ini seperti/)).not.toBeInTheDocument()
  })

  it('labels the edit dialog and warns about an import template', () => {
    renderEdit()
    expect(screen.getByText(/Mode Multiple Edit — mengubah data yang sudah ada/)).toBeInTheDocument()
    expect(screen.queryByText(/Nama file ini seperti/)).not.toBeInTheDocument()
    pick('template_lokasi.xlsx')
    expect(screen.getByText(/Nama file ini seperti template Import/)).toBeInTheDocument()
  })
})
