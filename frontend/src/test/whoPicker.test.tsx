import { render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { WhoPicker } from '../features/transactionTypes/FlowInputs'
import type { Who } from '../features/transactionTypes/api'

function setup(value: Who, props: { approval?: boolean; notify?: boolean }) {
  render(<WhoPicker label="Siapa" value={value} roles={[]} members={[]} fields={[]} onChange={vi.fn()} {...props} />)
  return screen.getByLabelText('Siapa')
}

describe('WhoPicker', () => {
  it('does not offer custodians as approvers: only Super Admin and Supervisor approve', () => {
    const select = setup({ type: 'location_supervisor' }, { approval: true })
    expect(within(select).queryByText(/Penanggung jawab aset/)).not.toBeInTheDocument()
    expect(screen.getByText('Hanya Super Admin dan Supervisor yang dapat menyetujui.')).toBeInTheDocument()
  })

  it('asks to choose again when a draft still names the custodian', () => {
    const select = setup({ type: 'asset_custodian' }, { approval: true })
    expect(select).toHaveValue('')
    expect(within(select).getByText('— Pilih lagi —')).toBeInTheDocument()
  })

  it('still offers custodians as email recipients', () => {
    expect(within(setup({ type: 'starter' }, { notify: true })).getByText('Penanggung jawab aset saat itu')).toBeInTheDocument()
  })
})
