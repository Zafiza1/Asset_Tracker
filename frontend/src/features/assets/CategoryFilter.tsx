import { ChevronDown } from 'lucide-react'
import type { SelectOption } from '../master/options'

/**
 * Pick one or more categories. Categories come from master data (never hard-coded);
 * a parent category also matches its subcategories (resolved by the API).
 * The chosen categories are shown as removable chips by the page's active-filter bar.
 */
export function CategoryFilter({ options, selected, onChange }: { options: SelectOption[]; selected: string[]; onChange: (ids: string[]) => void }) {
  const toggle = (id: string) => onChange(selected.includes(id) ? selected.filter((s) => s !== id) : [...selected, id])

  return (
    <details className="relative">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-1.5 rounded-md bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
        <span className="truncate">Kategori{selected.length > 0 ? ` (${selected.length})` : ': semua'}</span>
        <ChevronDown className="size-4 shrink-0 text-slate-400" aria-hidden />
      </summary>
      <fieldset className="absolute z-10 mt-1 max-h-72 w-72 overflow-y-auto rounded-md bg-white p-2 shadow-lg ring-1 ring-slate-200">
        <legend className="sr-only">Filter kategori</legend>
        {options.length === 0 && <p className="p-2 text-sm text-slate-500">Belum ada kategori.</p>}
        {options.map((o) => (
          <label key={o.value} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
            <input type="checkbox" className="size-4 rounded border-slate-300" checked={selected.includes(o.value)} onChange={() => toggle(o.value)} />
            <span className="whitespace-pre text-slate-800">{o.label}</span>
          </label>
        ))}
      </fieldset>
    </details>
  )
}
