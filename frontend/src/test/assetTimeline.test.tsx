import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AssetTimeline, formatChangeValue, type TimelineEvent } from '../features/assets/AssetTimeline'
import type { AssetTransaction } from '../features/transactions/api'

/** Timeline entries only, not the field-change rows inside them. */
const entries = () => Array.from(document.querySelectorAll<HTMLElement>('ol > li'))

const trx = (t: Partial<AssetTransaction>) =>
  ({ from_status: null, to_status: null, from_location: null, to_location: null, borrower_name: null, due_date: null, notes: null, ...t }) as AssetTransaction

// Newest first, as /assets/{id}/timeline returns it.
const events: TimelineEvent[] = [
  {
    id: 'trx-2',
    kind: 'transaction',
    occurred_at: '2026-11-10T03:00:00Z',
    actor: 'Budi',
    transaction: trx({ id: '2', type: 'transfer', type_label: 'Pemindahan lokasi', transaction_number: 'TRF-0001', transaction_date: '2026-11-10', from_location: { id: 'l1', code: 'G', name: 'Gudang' }, to_location: { id: 'l2', code: 'K', name: 'Kantor' } }),
  },
  {
    id: 'log-1',
    kind: 'data_change',
    occurred_at: '2026-11-09T03:00:00Z',
    actor: 'Ani',
    title: 'Data aset diubah',
    changes: [{ field: 'acquisition_cost', label: 'Harga perolehan', before: '1000', after: '2500' }],
  },
  {
    id: 'trx-1',
    kind: 'transaction',
    occurred_at: '2026-10-01T03:00:00Z',
    actor: 'Budi',
    transaction: trx({ id: '1', type: 'registration', type_label: 'Penambahan aset', transaction_number: 'REG-0001', transaction_date: '2026-10-01' }),
  },
]

describe('AssetTimeline', () => {
  it('groups events by month, newest first, with what changed', () => {
    render(<AssetTimeline events={events} />)
    const months = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)
    expect(months).toEqual(['November 2026', 'Oktober 2026'])

    const items = entries()
    expect(items.map((li) => li.querySelector('p')?.textContent)).toEqual(['Pemindahan lokasiTRF-0001', 'Data aset diubah', 'Penambahan asetREG-0001'])
    expect(within(items[0]).getByText('Kantor')).toBeInTheDocument()
    expect(within(items[1]).getByText('Harga perolehan:')).toBeInTheDocument()
    expect(within(items[1]).getByText('oleh Ani')).toBeInTheDocument()
  })

  it('filters transactions and data edits', () => {
    render(<AssetTimeline events={events} />)
    fireEvent.click(screen.getByRole('button', { name: 'Perubahan data' }))
    expect(entries()).toHaveLength(1)
    fireEvent.click(screen.getByRole('button', { name: 'Transaksi' }))
    expect(entries()).toHaveLength(2)
  })

  it('formats changed values for reading', () => {
    expect(formatChangeValue('description', null)).toBe('—')
    expect(formatChangeValue('attributes.garansi', true)).toBe('Ya')
    expect(formatChangeValue('attributes.warna', ['Merah', 'Biru'])).toBe('Merah, Biru')
    expect(formatChangeValue('acquisition_cost', '2500')).toMatch(/2\.500/)
  })
})
