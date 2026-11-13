import clsx from 'clsx'
import { MoreHorizontal } from 'lucide-react'
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

export interface RowMenuItem {
  label: string
  icon?: ReactNode
  onSelect: () => void
  tone?: 'default' | 'danger'
}

/**
 * "⋯" button that opens a list of actions for one table row. The menu is portalled with fixed
 * positioning, so the table's `overflow-hidden` cells and sideways scroll cannot clip it.
 */
export function RowMenu({ label, items }: { label: string; items: RowMenuItem[] }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; right: number; up: boolean } | null>(null)
  const button = useRef<HTMLButtonElement>(null)
  const menu = useRef<HTMLDivElement>(null)

  const place = () => {
    const rect = button.current?.getBoundingClientRect()
    if (!rect) return
    // Open upwards when the row is near the bottom of the screen.
    const up = window.innerHeight - rect.bottom < 48 * items.length + 16
    setPos({ top: up ? rect.top - 4 : rect.bottom + 4, right: window.innerWidth - rect.right, up })
  }

  useLayoutEffect(() => {
    if (open) menu.current?.querySelector<HTMLButtonElement>('[role="menuitem"]')?.focus()
  }, [open])

  useEffect(() => {
    if (!open) return
    const close = () => setOpen(false)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    return () => {
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [open])

  if (items.length === 0) return null

  const choose = (item: RowMenuItem) => {
    setOpen(false)
    item.onSelect()
  }

  const onMenuKey = (e: React.KeyboardEvent) => {
    const entries = [...(menu.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') ?? [])]
    const index = entries.indexOf(document.activeElement as HTMLButtonElement)
    if (e.key === 'Escape' || e.key === 'Tab') {
      e.preventDefault()
      setOpen(false)
      button.current?.focus()
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const step = e.key === 'ArrowDown' ? 1 : -1
      entries[(index + step + entries.length) % entries.length]?.focus()
    }
  }

  return (
    <>
      <button
        ref={button}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => (place(), setOpen((o) => !o))}
        className={clsx(
          'inline-grid size-9 place-items-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-brand-600',
          open && 'bg-slate-100 text-slate-800',
        )}
      >
        <MoreHorizontal className="size-4" aria-hidden />
      </button>
      {open &&
        pos &&
        createPortal(
          <>
            <div className="fixed inset-0 z-[55]" onClick={() => setOpen(false)} aria-hidden />
            <div
              ref={menu}
              role="menu"
              aria-label={label}
              onKeyDown={onMenuKey}
              style={{ top: pos.top, right: pos.right }}
              className={clsx('fixed z-[60] w-52 rounded-lg bg-white p-1 shadow-overlay ring-1 ring-slate-900/10', pos.up && '-translate-y-full')}
            >
              {items.map((item) => (
                <button
                  key={item.label}
                  type="button"
                  role="menuitem"
                  onClick={() => choose(item)}
                  className={clsx(
                    'flex min-h-10 w-full items-center gap-2.5 rounded-md px-3 text-left text-sm focus:outline-none',
                    item.tone === 'danger' ? 'text-red-700 hover:bg-red-50 focus:bg-red-50' : 'text-slate-700 hover:bg-slate-50 focus:bg-slate-100',
                  )}
                >
                  {item.icon && <span className="shrink-0 [&>svg]:size-4">{item.icon}</span>}
                  {item.label}
                </button>
              ))}
            </div>
          </>,
          document.body,
        )}
    </>
  )
}
