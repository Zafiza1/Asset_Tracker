import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { StatusBadge } from '../components/ui/Badge'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState, ErrorState, TableSkeleton } from '../components/ui/Feedback'
import { PillTabs, SegmentedControl } from '../components/ui/Tabs'
import { Toaster } from '../components/ui/Toaster'
import { toast } from '../components/ui/toast'
import { ApiError } from '../lib/api/client'

const renderToaster = () =>
  render(
    <MemoryRouter>
      <Toaster />
    </MemoryRouter>,
  )

describe('toast', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    act(() => toast.clear())
    vi.useRealTimers()
  })

  it('shows the outcome of an action with text, and closes by itself', () => {
    renderToaster()
    act(() => void toast.success('Aset ditambahkan', 'AST-1 · Laptop'))
    const card = screen.getByRole('status')
    expect(card).toHaveTextContent('Aset ditambahkan')
    expect(card).toHaveTextContent('AST-1 · Laptop')

    act(() => void vi.advanceTimersByTime(5000))
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('announces errors as alerts and keeps them longer', () => {
    renderToaster()
    act(() => void toast.error('Gagal menyimpan'))
    act(() => void vi.advanceTimersByTime(5000))
    expect(screen.getByRole('alert')).toHaveTextContent('Gagal menyimpan')
    act(() => void vi.advanceTimersByTime(3000))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('can be closed by hand and links to where the result lives', () => {
    renderToaster()
    act(() => void toast.show({ tone: 'pending', title: 'Menunggu persetujuan', action: { label: 'Lihat pengajuan saya', to: '/notifikasi' } }))
    expect(screen.getByRole('link', { name: 'Lihat pengajuan saya' })).toHaveAttribute('href', '/notifikasi')
    fireEvent.click(screen.getByRole('button', { name: 'Tutup notifikasi' }))
    expect(screen.queryByText('Menunggu persetujuan')).not.toBeInTheDocument()
  })

  it('does not claim success for a change that was only submitted for approval', () => {
    renderToaster()
    act(() => toast.saved({ data: { id: 'a1', summary: 's', action_label: 'l' }, meta: { approval_pending: true, message: 'm' } }, 'Aset dinonaktifkan'))
    expect(screen.queryByText('Aset dinonaktifkan')).not.toBeInTheDocument()

    act(() => toast.saved({ data: { id: 'asset-1' } }, 'Aset dinonaktifkan'))
    expect(screen.getByText('Aset dinonaktifkan')).toBeInTheDocument()
  })

  it('keeps at most four toasts on screen', () => {
    renderToaster()
    act(() => {
      for (let i = 1; i <= 6; i++) toast.info(`Pesan ${i}`)
    })
    expect(screen.getAllByRole('status')).toHaveLength(4)
    expect(screen.queryByText('Pesan 2')).not.toBeInTheDocument()
    expect(screen.getByText('Pesan 6')).toBeInTheDocument()
  })
})

describe('ConfirmDialog', () => {
  // jsdom does not open <dialog>, so it stays out of the accessibility tree.
  const dialog = () => screen.getByRole('dialog', { hidden: true })

  it('names the action, runs it on confirm and leaves on cancel', () => {
    const onConfirm = vi.fn()
    const onClose = vi.fn()
    render(
      <ConfirmDialog open tone="danger" title="Hapus role?" confirmLabel="Hapus role" onConfirm={onConfirm} onClose={onClose}>
        <p>Role akan dihapus.</p>
      </ConfirmDialog>,
    )
    expect(within(dialog()).getByText('Role akan dihapus.')).toBeInTheDocument()
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Hapus role', hidden: true }))
    expect(onConfirm).toHaveBeenCalledOnce()
    fireEvent.click(within(dialog()).getByRole('button', { name: 'Batal', hidden: true }))
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('stays open with the server message when the action is refused, and blocks a second click while it runs', () => {
    const { rerender } = render(
      <ConfirmDialog open title="Nonaktifkan?" confirmLabel="Nonaktifkan" loading onConfirm={vi.fn()} onClose={vi.fn()}>
        <p>Isi</p>
      </ConfirmDialog>,
    )
    expect(within(dialog()).getByRole('button', { name: 'Nonaktifkan', hidden: true })).toBeDisabled()
    expect(within(dialog()).getByRole('button', { name: 'Batal', hidden: true })).toBeDisabled()

    rerender(
      <ConfirmDialog open title="Nonaktifkan?" confirmLabel="Nonaktifkan" error={new ApiError(409, 'IN_USE', 'Masih dipakai 3 aset.')} onConfirm={vi.fn()} onClose={vi.fn()}>
        <p>Isi</p>
      </ConfirmDialog>,
    )
    expect(within(dialog()).getByRole('alert', { hidden: true })).toHaveTextContent('Masih dipakai 3 aset.')
  })
})

describe('status and state components', () => {
  it('gives every status an icon besides its color and text', () => {
    const { container } = render(
      <>
        <StatusBadge status="active">Aktif</StatusBadge>
        <StatusBadge status="archived">Diarsipkan</StatusBadge>
        <StatusBadge status="unknown-status">Lainnya</StatusBadge>
      </>,
    )
    expect(screen.getByText('Aktif').querySelector('svg')).not.toBeNull()
    expect(screen.getByText('Diarsipkan').querySelector('svg')).not.toBeNull()
    // An unmapped status still shows its text, on the neutral tone.
    expect(screen.getByText('Lainnya')).toBeInTheDocument()
    expect(container.querySelectorAll('svg')).toHaveLength(2)
  })

  it('shows an empty state with its explanation and next step', () => {
    render(<EmptyState title="Belum ada aset" description="Tambahkan aset pertama Anda." action={<button type="button">Tambah aset</button>} />)
    expect(screen.getByText('Belum ada aset')).toBeInTheDocument()
    expect(screen.getByText('Tambahkan aset pertama Anda.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tambah aset' })).toBeInTheDocument()
  })

  it('announces a loading table to screen readers without exposing its placeholder bars', () => {
    render(<TableSkeleton label="Memuat data aset…" />)
    expect(screen.getByRole('status')).toHaveTextContent('Memuat data aset…')
    expect(screen.getByRole('status')).toHaveAttribute('aria-busy', 'true')
  })

  it('offers to retry a failed load', () => {
    const onRetry = vi.fn()
    render(<ErrorState error={new ApiError(500, 'SERVER_ERROR', 'Server sedang bermasalah.', {}, 'req-123')} onRetry={onRetry} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Server sedang bermasalah.')
    expect(screen.getByRole('alert')).toHaveTextContent('req-123')
    fireEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('marks the selected tab and the pressed view for assistive technology', () => {
    const onTab = vi.fn()
    const onView = vi.fn()
    render(
      <>
        <PillTabs
          label="Status"
          value="pending"
          onChange={onTab}
          tabs={[
            { value: 'pending', label: 'Menunggu', count: 3, attention: true },
            { value: 'approved', label: 'Disetujui', count: 1200 },
          ]}
        />
        <SegmentedControl
          label="Tampilan"
          value="timeline"
          onChange={onView}
          options={[
            { value: 'timeline', label: 'Timeline' },
            { value: 'table', label: 'Tabel' },
          ]}
        />
      </>,
    )
    expect(screen.getByRole('tab', { name: /Menunggu\s*3/ })).toHaveAttribute('aria-selected', 'true')
    fireEvent.click(screen.getByRole('tab', { name: /Disetujui\s*999\+/ }))
    expect(onTab).toHaveBeenCalledWith('approved')

    expect(screen.getByRole('button', { name: 'Timeline' })).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(screen.getByRole('button', { name: 'Tabel' }))
    expect(onView).toHaveBeenCalledWith('table')
  })
})
