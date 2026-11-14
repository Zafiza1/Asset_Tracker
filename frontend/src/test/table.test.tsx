import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { DataTable, Pagination, type Column } from '../components/ui/Table'

interface Row {
  id: string
  name: string
}

const rows: Row[] = ['Laptop', 'Printer', 'Meja', 'Kursi'].map((name, i) => ({ id: `r${i}`, name }))
const columns: Column<Row>[] = [
  { key: 'name', header: 'Nama', sortKey: 'name', title: (r) => r.name, cell: (r) => r.name },
  { key: 'action', header: 'Aksi', cell: () => <button type="button">Cetak</button> },
]

function Selectable({ onRowClick = () => {} }: { onRowClick?: (row: Row) => void }) {
  const [selected, setSelected] = useState(new Set<string>())
  return (
    <>
      <p data-testid="picked">{[...selected].sort().join(',') || 'none'}</p>
      <DataTable
        columns={columns}
        rows={rows}
        onRowClick={onRowClick}
        rowHref={(r) => `/aset/${r.id}`}
        selection={{
          selected,
          rowLabel: (r) => r.name,
          onChange: (changed, pick) =>
            setSelected((prev) => {
              const next = new Set(prev)
              changed.forEach((r) => (pick ? next.add(r.id) : next.delete(r.id)))
              return next
            }),
        }}
      />
    </>
  )
}

describe('DataTable', () => {
  it('picks rows one by one, by Shift+click range and all on the page', () => {
    render(<Selectable />)
    const picked = screen.getByTestId('picked')
    fireEvent.click(screen.getByLabelText('Pilih Laptop'))
    expect(picked).toHaveTextContent('r0')
    // Shift on the cell's label (its hit area) extends the pick to a range.
    fireEvent.click(screen.getByLabelText('Pilih Meja').closest('label')!, { shiftKey: true })
    expect(picked).toHaveTextContent('r0,r1,r2')
    const all = screen.getByLabelText('Pilih semua baris di halaman ini') as HTMLInputElement
    expect(all.indeterminate).toBe(true)
    fireEvent.click(all)
    expect(picked).toHaveTextContent('r0,r1,r2,r3')
    fireEvent.click(screen.getByLabelText('Batalkan pilihan semua baris di halaman ini'))
    expect(picked).toHaveTextContent('none')
  })

  it('opens a row on click, in a new tab with Ctrl, and not from its checkbox or buttons', () => {
    const onRowClick = vi.fn()
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    render(<Selectable onRowClick={onRowClick} />)

    fireEvent.click(screen.getByText('Printer'))
    expect(onRowClick).toHaveBeenCalledWith(rows[1])

    fireEvent.click(screen.getByLabelText('Pilih Laptop'))
    fireEvent.click(screen.getAllByRole('button', { name: 'Cetak' })[0])
    expect(onRowClick).toHaveBeenCalledOnce()

    fireEvent.click(screen.getByText('Meja'), { ctrlKey: true })
    expect(open).toHaveBeenCalledWith('/aset/r2', '_blank', 'noopener')
    expect(onRowClick).toHaveBeenCalledOnce()
    open.mockRestore()
  })

  it('shows the full value as a tooltip and cycles the sort of a header', () => {
    const onSort = vi.fn()
    const { rerender } = render(<DataTable columns={columns} rows={rows} sort="" onSort={onSort} />)
    expect(screen.getByText('Laptop')).toHaveAttribute('title', 'Laptop')

    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(onSort).toHaveBeenLastCalledWith('name')
    rerender(<DataTable columns={columns} rows={rows} sort="name" onSort={onSort} />)
    expect(screen.getByRole('columnheader', { name: /Nama/ })).toHaveAttribute('aria-sort', 'ascending')
    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(onSort).toHaveBeenLastCalledWith('-name')
    rerender(<DataTable columns={columns} rows={rows} sort="-name" onSort={onSort} />)
    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(onSort).toHaveBeenLastCalledWith('')
  })

  it('sorts the rows itself by sortValue when the table has no onSort, empty values last', () => {
    interface Item {
      id: string
      name: string
      count: number | null
    }
    const items: Item[] = [
      { id: 'a', name: 'Printer', count: 10 },
      { id: 'b', name: 'laptop', count: null },
      { id: 'c', name: 'Meja', count: 2 },
    ]
    const itemColumns: Column<Item>[] = [
      { key: 'name', header: 'Nama', sortValue: (r) => r.name, cell: (r) => r.name },
      { key: 'count', header: 'Jumlah', sortValue: (r) => r.count, cell: (r) => r.count ?? '—' },
      { key: 'note', header: 'Catatan', cell: () => 'x' },
    ]
    render(<DataTable columns={itemColumns} rows={items} />)
    const names = () => screen.getAllByRole('row').slice(1).map((r) => r.querySelector('td')!.textContent)

    expect(names()).toEqual(['Printer', 'laptop', 'Meja'])
    // Case does not matter: "laptop" sorts before "Meja".
    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(names()).toEqual(['laptop', 'Meja', 'Printer'])
    expect(screen.getByRole('columnheader', { name: /Nama/ })).toHaveAttribute('aria-sort', 'ascending')
    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(names()).toEqual(['Printer', 'Meja', 'laptop'])
    fireEvent.click(screen.getByRole('button', { name: /Nama/ }))
    expect(names()).toEqual(['Printer', 'laptop', 'Meja'])

    // Numbers compare as numbers (2 before 10), and the empty count stays last both ways.
    fireEvent.click(screen.getByRole('button', { name: /Jumlah/ }))
    expect(names()).toEqual(['Meja', 'Printer', 'laptop'])
    fireEvent.click(screen.getByRole('button', { name: /Jumlah/ }))
    expect(names()).toEqual(['Printer', 'Meja', 'laptop'])

    // A column without a sort value is a plain header.
    expect(screen.queryByRole('button', { name: /Catatan/ })).toBeNull()
  })
})

describe('Pagination', () => {
  const meta = { current_page: 2, last_page: 8, per_page: 25, total: 190, from: 26, to: 50 }

  it('changes rows per page, jumps to a page and to the first and last page', () => {
    const onPage = vi.fn()
    const onPerPage = vi.fn()
    render(<Pagination meta={meta} onPage={onPage} onPerPage={onPerPage} />)

    fireEvent.change(screen.getByLabelText('Baris per halaman'), { target: { value: '50' } })
    expect(onPerPage).toHaveBeenCalledWith(50)

    const page = screen.getByLabelText(/Nomor halaman/)
    fireEvent.change(page, { target: { value: '99' } })
    fireEvent.submit(page.closest('form')!)
    expect(onPage).toHaveBeenLastCalledWith(8)

    fireEvent.click(screen.getByRole('button', { name: 'Halaman pertama' }))
    expect(onPage).toHaveBeenLastCalledWith(1)
    fireEvent.click(screen.getByRole('button', { name: 'Halaman terakhir' }))
    expect(onPage).toHaveBeenLastCalledWith(8)
  })

  it('stays a plain previous/next pager without onPerPage', () => {
    render(<Pagination meta={meta} onPage={() => {}} />)
    expect(screen.getByText('Hal. 2 / 8')).toBeInTheDocument()
    expect(screen.queryByLabelText('Baris per halaman')).toBeNull()
  })
})
