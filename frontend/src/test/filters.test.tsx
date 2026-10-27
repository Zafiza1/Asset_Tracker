import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, useSearchParams } from 'react-router'
import { describe, expect, it } from 'vitest'
import { AdvancedFilters, useFilterDraft } from '../components/ui/Filters'

function Harness() {
  const [params, setParams] = useSearchParams()
  const advanced = useFilterDraft(params, ['status'])
  return (
    <>
      <p data-testid="applied">{params.get('status') ?? 'semua'}</p>
      <button type="button" onClick={() => setParams(new URLSearchParams())}>
        Reset semua
      </button>
      <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => setParams(new URLSearchParams(advanced.draft))} onClear={() => advanced.set(advanced.empty)}>
        <select aria-label="Filter status" value={advanced.draft.status} onChange={(e) => advanced.set({ status: e.target.value })}>
          <option value="">Semua</option>
          <option value="active">Aktif</option>
        </select>
      </AdvancedFilters>
    </>
  )
}

describe('AdvancedFilters', () => {
  it('applies filter changes only on "Terapkan filter" and follows external resets', () => {
    render(
      <MemoryRouter>
        <Harness />
      </MemoryRouter>,
    )
    expect(screen.queryByLabelText('Filter status')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Filter lanjutan/ }))

    fireEvent.change(screen.getByLabelText('Filter status'), { target: { value: 'active' } })
    expect(screen.getByTestId('applied')).toHaveTextContent('semua')
    expect(screen.getByText('Perubahan filter belum diterapkan.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Terapkan filter' }))
    expect(screen.getByTestId('applied')).toHaveTextContent('active')
    expect(screen.getByRole('button', { name: 'Terapkan filter' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Reset semua' }))
    expect(screen.getByLabelText('Filter status')).toHaveValue('')
  })
})
