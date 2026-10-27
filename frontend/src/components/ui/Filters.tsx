import { ChevronDown, SlidersHorizontal, Search, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Button } from './Button'
import { Input } from './Field'
import type { FilterChip } from './filterChips'

/**
 * Building blocks for the filter area of every list page, so they all read the same:
 *   search row (plus an optional control such as sorting)
 *   collapsible "Filter lanjutan" with a grid of equal-width filters, applied with "Terapkan filter"
 *   applied filters as removable chips with "reset all"
 */
export function FilterPanel({ children }: { children: ReactNode }) {
  return <div className="space-y-3 border-b border-slate-100 p-4">{children}</div>
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
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <Input type="search" value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="pl-9" aria-label={label} />
        </div>
        <Button type="submit" variant="secondary">
          Cari
        </Button>
      </form>
      {aside}
    </div>
  )
}

/**
 * Filter values being edited but not yet applied. Starts from the URL and follows it whenever the
 * applied filters change elsewhere (a removed chip, "reset semua"), so the form never shows stale values.
 */
export function useFilterDraft(params: URLSearchParams, keys: readonly string[]) {
  const applied: Record<string, string> = Object.fromEntries(keys.map((k) => [k, params.get(k) ?? '']))
  const appliedKey = JSON.stringify(applied)
  const [draft, setDraft] = useState(applied)
  const [syncedKey, setSyncedKey] = useState(appliedKey)
  if (syncedKey !== appliedKey) {
    setSyncedKey(appliedKey)
    setDraft(applied)
  }

  return {
    draft,
    set: (next: Record<string, string>) => setDraft((d) => ({ ...d, ...next })),
    /** Every filter in the draft set back to "semua", without applying. */
    empty: Object.fromEntries(keys.map((k) => [k, ''])) as Record<string, string>,
    dirty: keys.some((k) => draft[k] !== applied[k]),
    activeCount: keys.filter((k) => applied[k]).length,
  }
}

/**
 * Advanced filters behind a toggle. Changes stay in the draft until "Terapkan filter" (or Enter),
 * so several filters can be set before the list reloads.
 */
export function AdvancedFilters({ activeCount, dirty, onApply, onClear, children }: { activeCount: number; dirty: boolean; onApply: () => void; onClear: () => void; children: ReactNode }) {
  const [open, setOpen] = useState(false)

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 rounded-md px-1 py-0.5 text-sm font-semibold text-slate-700 hover:text-brand-700"
      >
        <SlidersHorizontal className="size-4" aria-hidden />
        Filter lanjutan
        {activeCount > 0 && <span className="rounded-full bg-brand-700 px-1.5 text-xs font-semibold text-white">{activeCount}</span>}
        <ChevronDown className={`size-4 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open && (
        <form
          className="mt-2 space-y-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200"
          onSubmit={(e) => {
            e.preventDefault()
            onApply()
          }}
        >
          <FilterGrid>{children}</FilterGrid>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {dirty && <span className="mr-auto text-xs text-amber-700">Perubahan filter belum diterapkan.</span>}
            <Button variant="ghost" size="sm" onClick={onClear}>
              Kosongkan
            </Button>
            <Button type="submit" size="sm" disabled={!dirty}>
              Terapkan filter
            </Button>
          </div>
        </form>
      )}
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
          <button type="button" onClick={c.clear} className="grid size-5 place-items-center rounded-full transition-colors hover:bg-brand-200" aria-label={`Hapus filter ${c.label}`}>
            <X className="size-3" aria-hidden />
          </button>
        </span>
      ))}
      <button type="button" className="rounded px-1 py-0.5 text-xs font-semibold text-brand-700 hover:underline" onClick={onReset}>
        Reset semua
      </button>
    </div>
  )
}
