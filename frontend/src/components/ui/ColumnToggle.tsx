import { ChevronDown, Settings2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from './Button'

interface ColumnToggleProps {
  columns: { key: string; header: string }[]
  visibleColumns: string[]
  onToggle: (key: string) => void
  onReset: () => void
  /** Button text and panel heading; defaults fit a table's column picker. */
  label?: string
  title?: string
}

export function ColumnToggle({ columns, visibleColumns, onToggle, onReset, label = 'Kolom', title = 'Tampilkan kolom' }: ColumnToggleProps) {
  const [open, setOpen] = useState(false)

  return (
    <div className="relative">
      <Button variant="secondary" size="sm" onClick={() => setOpen(!open)} aria-expanded={open} aria-haspopup="true">
        <Settings2 className="size-4" aria-hidden />
        <span className="hidden sm:inline">{label}</span>
        <ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </Button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="absolute right-0 z-20 mt-2 w-64 animate-fade-in rounded-lg bg-white p-3 shadow-overlay ring-1 ring-slate-900/10">
            <div className="mb-2 flex items-center justify-between">
              <span className="text-sm font-semibold text-slate-700">{title}</span>
              <button type="button" onClick={onReset} className="rounded px-1 text-xs font-semibold text-brand-700 hover:text-brand-800 hover:underline">
                Reset
              </button>
            </div>
            <div className="space-y-1">
              {columns.map((col) => (
                <label key={col.key} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-slate-50">
                  <input
                    type="checkbox"
                    checked={visibleColumns.includes(col.key)}
                    onChange={() => onToggle(col.key)}
                    className="size-4 rounded border-slate-300 accent-brand-700"
                  />
                  <span className="text-sm text-slate-700">{col.header}</span>
                </label>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
