import clsx from 'clsx'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { ReactNode } from 'react'
import type { Paginated } from '../../lib/api/types'
import { Button } from './Button'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  className?: string
  /** Hide on small screens to keep tables readable on mobile. */
  hideOnMobile?: boolean
}

export function DataTable<T extends { id: string }>({ columns, rows, onRowClick }: { columns: Column<T>[]; rows: T[]; onRowClick?: (row: T) => void }) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50/80">
          <tr>
            {columns.map((c) => (
              <th key={c.key} scope="col" className={clsx('whitespace-nowrap px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500', c.hideOnMobile && 'hidden md:table-cell', c.className)}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {rows.map((row) => (
            <tr
              key={row.id}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              // Clickable rows are reachable and operable by keyboard too (Enter / Space).
              tabIndex={onRowClick ? 0 : undefined}
              onKeyDown={
                onRowClick
                  ? (e) => {
                      if (e.target !== e.currentTarget || (e.key !== 'Enter' && e.key !== ' ')) return
                      e.preventDefault()
                      onRowClick(row)
                    }
                  : undefined
              }
              className={clsx('transition-colors duration-100', onRowClick && 'cursor-pointer hover:bg-brand-50/60 focus-visible:bg-brand-50/60 focus-visible:outline-offset-[-2px]')}
            >
              {columns.map((c) => (
                <td key={c.key} className={clsx('px-4 py-3 align-top text-slate-700', c.hideOnMobile && 'hidden md:table-cell', c.className)}>
                  {c.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function Pagination({ meta, onPage }: { meta: Paginated<unknown>['meta']; onPage: (page: number) => void }) {
  if (meta.total === 0) return null
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
      <span className="tabular-nums">
        Menampilkan <span className="font-semibold text-slate-800">{meta.from}–{meta.to}</span> dari <span className="font-semibold text-slate-800">{meta.total}</span>
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} aria-label="Halaman sebelumnya">
          <ChevronLeft className="size-4" aria-hidden />
        </Button>
        <span className="tabular-nums">
          Hal. {meta.current_page} / {meta.last_page}
        </span>
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} aria-label="Halaman berikutnya">
          <ChevronRight className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  )
}
