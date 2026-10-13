import { Search, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from './Button'
import { Input } from './Field'
import type { FilterChip } from './filterChips'

/**
 * Building blocks for the filter area of every list page, so they all read the same:
 *   search row (plus an optional control such as sorting)
 *   grid of equal-width filters
 *   applied filters as removable chips with "reset all"
 */
export function FilterPanel({ children }: { children: ReactNode }) {
  return <div className="space-y-3 border-b border-slate-200 p-3">{children}</div>
}

export function SearchForm({ value, onChange, onSubmit, placeholder, label, aside }: { value: string; onChange: (value: string) => void; onSubmit: () => void; placeholder: string; label: string; aside?: ReactNode }) {
  return (
    <div className="flex flex-col gap-2 sm:flex-row">
      <form
        role="search"
        className="flex flex-1 gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          onSubmit()
        }}
      >
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
          <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="pl-8" aria-label={label} />
        </div>
        <Button type="submit" variant="secondary">
          Cari
        </Button>
      </form>
      {aside}
    </div>
  )
}

/** Equal-width filter controls that wrap evenly at any screen width. */
export function FilterGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fill,minmax(12rem,1fr))] gap-2">{children}</div>
}

/** Two grid cells: "Dari" and "Sampai" dates, each limited by the other. */
export function DateRangeFilter({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
  return (
    <>
      <label className="flex items-center gap-2 text-sm text-slate-500">
        <span className="w-12 shrink-0">Dari</span>
        <Input type="date" value={from} max={to || undefined} onChange={(e) => onChange(e.target.value, to)} />
      </label>
      <label className="flex items-center gap-2 text-sm text-slate-500">
        <span className="w-12 shrink-0">Sampai</span>
        <Input type="date" value={to} min={from || undefined} onChange={(e) => onChange(from, e.target.value)} />
      </label>
    </>
  )
}

/** Applied filters as removable chips, with one "reset all" action. */
export function ActiveFilters({ chips, onReset }: { chips: FilterChip[]; onReset: () => void }) {
  if (chips.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-xs text-slate-500">Filter aktif:</span>
      {chips.map((c) => (
        <span key={c.key} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-1 pl-2.5 pr-1 text-xs font-medium text-brand-800 ring-1 ring-inset ring-brand-200">
          {c.label}
          <button type="button" onClick={c.clear} className="rounded-full p-0.5 hover:bg-brand-100" aria-label={`Hapus filter ${c.label}`}>
            <X className="size-3" aria-hidden />
          </button>
        </span>
      ))}
      <button type="button" className="text-xs font-medium text-brand-700 hover:underline" onClick={onReset}>
        Reset semua
      </button>
    </div>
  )
}
