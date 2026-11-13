import { ChevronDown, FilePlus2, Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { Button } from './Button'

interface AddMenuProps {
  /** Button text, e.g. "Tambah aset". */
  label: string
  /** What the menu items add, e.g. "aset" → "Tambah satu aset", "Tambah banyak aset baru sekaligus". */
  noun: string
  onForm: () => void
  onImport: () => void
}

/**
 * The "Tambah" button with its two ways of adding: one record through the form, or many
 * from an Excel template. Import lives here, not beside Multiple Edit, so a file for adding
 * records is not confused with the sheet that edits existing ones.
 */
export function AddMenu({ label, noun, onForm, onImport }: AddMenuProps) {
  const [open, setOpen] = useState(false)
  const choose = (action: () => void) => () => {
    setOpen(false)
    action()
  }

  return (
    <div className="relative" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <Button onClick={() => setOpen(!open)} aria-expanded={open} aria-haspopup="menu">
        <Plus className="size-4" aria-hidden /> {label}
        <ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </Button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div
            role="menu"
            aria-label={`Cara menambah ${noun}`}
            className="absolute right-0 z-20 mt-2 w-72 animate-fade-in rounded-lg bg-white p-1.5 shadow-overlay ring-1 ring-slate-900/10"
          >
            <MenuItem icon={<FilePlus2 className="size-4" aria-hidden />} title="Isi formulir" hint={`Tambah satu ${noun}`} onClick={choose(onForm)} />
            <MenuItem
              icon={<Upload className="size-4" aria-hidden />}
              title="Import dari Excel"
              hint={`Tambah banyak ${noun} baru sekaligus lewat template`}
              onClick={choose(onImport)}
            />
          </div>
        </>
      )}
    </div>
  )
}

function MenuItem({ icon, title, hint, onClick }: { icon: React.ReactNode; title: string; hint: string; onClick: () => void }) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      className="flex w-full items-start gap-3 rounded-md px-3 py-2 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
    >
      <span className="mt-0.5 text-brand-700">{icon}</span>
      <span>
        <span className="block text-sm font-medium text-slate-900">{title}</span>
        <span className="block text-xs text-slate-500">{hint}</span>
      </span>
    </button>
  )
}
