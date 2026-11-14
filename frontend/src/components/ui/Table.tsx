import clsx from 'clsx'
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'
import { useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react'
import type { Paginated } from '../../lib/api/types'
import { Button } from './Button'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  className?: string
  /** Hide on small screens to keep tables readable on mobile. */
  hideOnMobile?: boolean
  /** Default visibility (used for column toggle). */
  defaultVisible?: boolean
  /** Full text of the cell as a tooltip, for values that may be cut off with "…". */
  title?: (row: T) => string | null | undefined
  /** API sort key; with `onSort` on the table the header becomes a sort button. */
  sortKey?: string
}

/** Row checkboxes: which ids are picked, and a callback to pick or drop several rows at once. */
export interface RowSelection<T> {
  selected: ReadonlySet<string>
  onChange: (rows: T[], selected: boolean) => void
  /** Accessible name of a row's checkbox, e.g. the asset name. */
  rowLabel: (row: T) => string
}

/** Clicks on these inside a row do their own thing instead of opening the row. */
const INTERACTIVE = 'a,button,input,select,textarea,label,[role="menu"],[data-row-ignore]'

function HeaderCheckbox({ checked, indeterminate, onChange }: { checked: boolean; indeterminate: boolean; onChange: () => void }) {
  const ref = useRef<HTMLInputElement>(null)
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate
  }, [indeterminate])
  return (
    <input
      ref={ref}
      type="checkbox"
      className="size-4 cursor-pointer rounded border-slate-300 accent-brand-700"
      checked={checked}
      onChange={onChange}
      aria-label={checked ? 'Batalkan pilihan semua baris di halaman ini' : 'Pilih semua baris di halaman ini'}
    />
  )
}

/**
 * Columns with a width class keep it; the others share what is left. Pass a min-width in
 * `className` when the fixed columns could leave the shared ones too little: the table then
 * scrolls sideways instead of squeezing them.
 *
 * Optional extras: row checkboxes (`selection`, Shift+click picks a range), sortable headers
 * (`sort` + `onSort`, cycling ascending → descending → default) and `rowHref`, which lets
 * Ctrl/⌘+click and middle-click open a row in a new tab.
 */
export function DataTable<T extends { id: string }>({
  columns,
  rows,
  onRowClick,
  rowHref,
  visibleColumns,
  className,
  selection,
  sort,
  onSort,
}: {
  columns: Column<T>[]
  rows: T[]
  onRowClick?: (row: T) => void
  rowHref?: (row: T) => string
  visibleColumns?: string[]
  className?: string
  selection?: RowSelection<T>
  sort?: string
  onSort?: (sort: string) => void
}) {
  const displayedColumns = visibleColumns ? columns.filter((c) => visibleColumns.includes(c.key)) : columns
  const lastPicked = useRef<number | null>(null)
  const shiftHeld = useRef(false)

  const pickedOnPage = selection ? rows.filter((r) => selection.selected.has(r.id)).length : 0
  const allPicked = rows.length > 0 && pickedOnPage === rows.length

  const togglePick = (index: number) => {
    if (!selection) return
    const row = rows[index]
    const next = !selection.selected.has(row.id)
    if (shiftHeld.current && lastPicked.current !== null && lastPicked.current !== index) {
      const [from, to] = [Math.min(lastPicked.current, index), Math.max(lastPicked.current, index)]
      selection.onChange(rows.slice(from, to + 1), next)
    } else {
      selection.onChange([row], next)
    }
    lastPicked.current = index
    shiftHeld.current = false
  }

  const open = (row: T, e: MouseEvent<HTMLTableRowElement>) => {
    if ((e.target as HTMLElement).closest(INTERACTIVE)) return
    // Selecting text (e.g. to copy an asset number) is not a click on the row.
    if (window.getSelection()?.toString()) return
    const newTab = rowHref && (e.ctrlKey || e.metaKey || e.button === 1)
    if (newTab) {
      e.preventDefault()
      window.open(rowHref(row), '_blank', 'noopener')
    } else if (e.button === 0) {
      onRowClick?.(row)
    }
  }

  const sortState = (key?: string): 'asc' | 'desc' | null => (!key || !sort ? null : sort === key ? 'asc' : sort === `-${key}` ? 'desc' : null)
  const nextSort = (key: string) => {
    const state = sortState(key)
    return state === null ? key : state === 'asc' ? `-${key}` : ''
  }

  return (
    <div className="overflow-x-auto overscroll-x-contain">
      <table className={clsx('w-full table-fixed divide-y divide-slate-200 text-sm', className)}>
        <thead className="bg-slate-100/70">
          <tr>
            {selection && (
              <th scope="col" className="w-11 px-3 py-3 text-left">
                <HeaderCheckbox checked={allPicked} indeterminate={pickedOnPage > 0 && !allPicked} onChange={() => selection.onChange(rows, !allPicked)} />
              </th>
            )}
            {displayedColumns.map((c) => {
              const state = sortState(c.sortKey)
              const Icon = state === 'asc' ? ArrowUp : state === 'desc' ? ArrowDown : ArrowUpDown
              return (
                <th
                  key={c.key}
                  scope="col"
                  aria-sort={state === 'asc' ? 'ascending' : state === 'desc' ? 'descending' : undefined}
                  className={clsx('whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600', c.hideOnMobile && 'hidden md:table-cell', c.className)}
                >
                  {c.sortKey && onSort ? (
                    <button
                      type="button"
                      onClick={() => onSort(nextSort(c.sortKey!))}
                      className={clsx('-mx-1 inline-flex items-center gap-1 rounded px-1 uppercase tracking-wide hover:text-slate-900', state && 'text-slate-900')}
                      title={state === 'asc' ? 'Urut naik — klik untuk urut turun' : state === 'desc' ? 'Urut turun — klik untuk urutan bawaan' : `Urutkan menurut ${c.header.toLowerCase()}`}
                    >
                      {c.header}
                      <Icon className={clsx('size-3.5', !state && 'text-slate-400')} aria-hidden />
                    </button>
                  ) : (
                    c.header
                  )}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {rows.map((row, index) => {
            const picked = selection?.selected.has(row.id) ?? false
            return (
              <tr
                key={row.id}
                onClick={onRowClick ? (e) => open(row, e) : undefined}
                onAuxClick={onRowClick && rowHref ? (e) => open(row, e) : undefined}
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
                aria-selected={selection ? picked : undefined}
                className={clsx(
                  'transition-colors duration-100',
                  picked && 'bg-brand-50/40',
                  onRowClick ? 'cursor-pointer hover:bg-brand-50 focus-visible:bg-brand-50 focus-visible:outline-offset-[-2px]' : !picked && 'hover:bg-slate-50/70',
                )}
              >
                {selection && (
                  <td className="p-0 align-middle">
                    {/* The whole cell is the hit area, so the checkbox is easy to hit on touch screens too. */}
                    <label
                      className="flex min-h-12 cursor-pointer select-none items-center px-3"
                      onClick={(e) => {
                        // A click on the label reaches here twice: itself, then the click it forwards
                        // to the checkbox, which may not carry Shift. Keep what the first one saw.
                        if (e.shiftKey || e.target === e.currentTarget) shiftHeld.current = e.shiftKey
                      }}
                    >
                      <input
                        type="checkbox"
                        className="size-4 cursor-pointer rounded border-slate-300 accent-brand-700"
                        checked={picked}
                        onChange={() => togglePick(index)}
                        aria-label={`Pilih ${selection.rowLabel(row)}`}
                      />
                    </label>
                  </td>
                )}
                {displayedColumns.map((c) => (
                  <td
                    key={c.key}
                    title={c.title?.(row) ?? undefined}
                    className={clsx(
                      'overflow-hidden px-4 py-3 align-middle text-slate-700',
                      // Cells stay on one line and end in "…" unless the column opts into wrapping.
                      c.className?.includes('whitespace-normal') ? 'break-words' : 'text-ellipsis whitespace-nowrap',
                      c.hideOnMobile && 'hidden md:table-cell',
                      c.className,
                    )}
                  >
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

const PER_PAGE_OPTIONS = [10, 25, 50, 100]

/**
 * Previous/next paging with the range shown. With `onPerPage` it also offers rows per page,
 * first/last page and a page number field to jump to.
 */
export function Pagination({ meta, onPage, onPerPage, perPageOptions = PER_PAGE_OPTIONS }: { meta: Paginated<unknown>['meta']; onPage: (page: number) => void; onPerPage?: (perPage: number) => void; perPageOptions?: number[] }) {
  const [jump, setJump] = useState(String(meta.current_page))
  const [shownPage, setShownPage] = useState(meta.current_page)
  if (shownPage !== meta.current_page) {
    setShownPage(meta.current_page)
    setJump(String(meta.current_page))
  }
  if (meta.total === 0) return null
  const extended = onPerPage !== undefined

  return (
    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span className="tabular-nums">
          Menampilkan <span className="font-semibold text-slate-800">{meta.from}–{meta.to}</span> dari <span className="font-semibold text-slate-800">{meta.total}</span>
        </span>
        {extended && (
          <label className="flex items-center gap-2">
            <span>Baris per halaman</span>
            <select
              value={meta.per_page}
              onChange={(e) => onPerPage(Number(e.target.value))}
              className="min-h-8 rounded-md border-0 bg-white py-1 pl-2 pr-7 text-sm text-slate-800 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
            >
              {[...new Set([...perPageOptions, meta.per_page])].sort((a, b) => a - b).map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      <div className="flex items-center gap-1.5">
        {extended && (
          <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(1)} aria-label="Halaman pertama" title="Halaman pertama">
            <ChevronsLeft className="size-4" aria-hidden />
          </Button>
        )}
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} aria-label="Halaman sebelumnya" title="Halaman sebelumnya">
          <ChevronLeft className="size-4" aria-hidden />
        </Button>
        {extended && meta.last_page > 1 ? (
          <form
            className="flex items-center gap-1.5 px-1"
            onSubmit={(e) => {
              e.preventDefault()
              const page = Math.min(meta.last_page, Math.max(1, Math.round(Number(jump)) || 1))
              setJump(String(page))
              if (page !== meta.current_page) onPage(page)
            }}
          >
            <span>Hal.</span>
            <input
              type="number"
              inputMode="numeric"
              min={1}
              max={meta.last_page}
              value={jump}
              onChange={(e) => setJump(e.target.value)}
              onBlur={() => setJump(String(meta.current_page))}
              className="min-h-8 w-14 rounded-md border-0 bg-white px-2 py-1 text-center text-sm tabular-nums text-slate-800 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
              aria-label={`Nomor halaman (1–${meta.last_page}), tekan Enter untuk pindah`}
            />
            <span className="tabular-nums">/ {meta.last_page}</span>
          </form>
        ) : (
          <span className="px-1 tabular-nums">
            Hal. {meta.current_page} / {meta.last_page}
          </span>
        )}
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} aria-label="Halaman berikutnya" title="Halaman berikutnya">
          <ChevronRight className="size-4" aria-hidden />
        </Button>
        {extended && (
          <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.last_page)} aria-label="Halaman terakhir" title="Halaman terakhir">
            <ChevronsRight className="size-4" aria-hidden />
          </Button>
        )}
      </div>
    </div>
  )
}
