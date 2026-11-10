import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AddMenu } from '../components/ui/AddMenu'

describe('AddMenu', () => {
  it('offers the form and the Excel import, and closes after a choice', () => {
    const onForm = vi.fn()
    const onImport = vi.fn()
    render(<AddMenu label="Tambah lokasi" noun="lokasi" onForm={onForm} onImport={onImport} />)

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Tambah lokasi/ }))
    expect(screen.getByText('Tambah banyak lokasi baru sekaligus lewat template')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('menuitem', { name: /Import dari Excel/ }))
    expect(onImport).toHaveBeenCalledOnce()
    expect(onForm).not.toHaveBeenCalled()
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Tambah lokasi/ }))
    fireEvent.click(screen.getByRole('menuitem', { name: /Isi formulir/ }))
    expect(onForm).toHaveBeenCalledOnce()
  })

  it('closes on Escape', () => {
    render(<AddMenu label="Tambah aset" noun="aset" onForm={vi.fn()} onImport={vi.fn()} />)
    fireEvent.click(screen.getByRole('button', { name: /Tambah aset/ }))
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })
})
