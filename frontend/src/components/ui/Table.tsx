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
        <thead className="bg-slate-50">
          <tr>
            {columns.map((c) => (
              <th key={c.key} scope="col" className={clsx('px-4 py-2.5 text-left font-semibold text-slate-600', c.hideOnMobile && 'hidden md:table-cell', c.className)}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {rows.map((row) => (
            <tr key={row.id} onClick={onRowClick ? () => onRowClick(row) : undefined} className={clsx(onRowClick && 'cursor-pointer hover:bg-brand-50/50')}>
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
      <span>
        {meta.from}–{meta.to} dari {meta.total}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} aria-label="Halaman sebelumnya">
          <ChevronLeft className="size-4" aria-hidden />
        </Button>
        <span>
          Hal. {meta.current_page} / {meta.last_page}
        </span>
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} aria-label="Halaman berikutnya">
          <ChevronRight className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  )
}
