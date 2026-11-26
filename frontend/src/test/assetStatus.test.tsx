import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AssetStatusBadge } from '../features/assets/AssetStatusBadge'
import { initialStatuses, statusFilterLabel, withoutInUse } from '../features/assets/api'

describe('asset status of cylinders', () => {
  it('names an available cylinder by where it is, other assets stay "Tersedia"', () => {
    const { rerender } = render(<AssetStatusBadge status="available" cylinder />)
    expect(screen.getByText('Di Gudang')).toBeInTheDocument()
    rerender(<AssetStatusBadge status="available" />)
    expect(screen.getByText('Tersedia')).toBeInTheDocument()
    rerender(<AssetStatusBadge status="off_site" cylinder />)
    expect(screen.getByText('Di Customer / Dikirim')).toBeInTheDocument()
    expect(statusFilterLabel.available).toBe('Tersedia / Di Gudang')
  })

  it('offers no Aktif for a cylinder', () => {
    expect(withoutInUse(initialStatuses, true)).toEqual(['available', 'under_maintenance'])
    expect(withoutInUse(initialStatuses, false)).toContain('in_use')
  })
})
