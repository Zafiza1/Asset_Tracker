import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { withStateAfter, type AssetTransaction } from '../features/transactions/api'
import { LocationChange, StatusChange } from '../features/transactions/TransactionChanges'

const gudang = { id: 'l1', code: 'GDG', name: 'Gudang' }
const kantor = { id: 'l2', code: 'KTR', name: 'Kantor' }
const trx = (t: Partial<AssetTransaction>) => ({ from_status: null, to_status: null, from_location: null, to_location: null, ...t }) as AssetTransaction

describe('withStateAfter', () => {
  it('fills in the status a transfer kept and the location a status change kept', () => {
    // Newest first, as /assets/{id}/transactions returns it.
    const history = [
      trx({ id: 't3', type: 'status_change', from_status: 'available', to_status: 'under_maintenance' }),
      trx({ id: 't2', type: 'transfer', from_location: gudang, to_location: kantor }),
      trx({ id: 't1', type: 'registration', to_status: 'available', to_location: gudang }),
    ]
    const rows = withStateAfter(history, { location: kantor, status: 'under_maintenance' })

    expect(rows.map((r) => [r.id, r.location_after, r.status_after])).toEqual([
      ['t3', 'Kantor', 'under_maintenance'],
      ['t2', 'Kantor', 'available'],
      ['t1', 'Gudang', 'available'],
    ])
  })

  it('shows the kept status of a new asset that was only transferred', () => {
    const history = [
      trx({ id: 't2', type: 'transfer', from_location: gudang, to_location: kantor }),
      trx({ id: 't1', type: 'registration', to_status: 'available', to_location: gudang }),
    ]
    const [transfer] = withStateAfter(history, { location: kantor, status: 'available' })
    render(
      <p>
        <LocationChange trx={transfer} /> | <StatusChange trx={transfer} />
      </p>,
    )
    expect(screen.getByText(/Gudang/).closest('p')).toHaveTextContent('GudangKantor | Tersedia')
  })
})
