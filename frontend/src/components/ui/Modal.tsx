import clsx from 'clsx'
import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ReactNode } from 'react'

/** Accessible dialog on the native <dialog> element (modal focus handling and Esc built in). */
export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
  size = 'md',
}: {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  size?: 'md' | 'xl' | 'full'
}) {
  const ref = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal?.()
    if (!open && dialog.open) dialog.close?.()
  }, [open])

  return (
    <dialog
      ref={ref}
      onClose={onClose}
      aria-labelledby={titleId}
      className={clsx(
        'm-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] overflow-hidden rounded-xl p-0 shadow-overlay ring-1 ring-slate-900/5 open:animate-dialog-in backdrop:bg-slate-900/50 backdrop:backdrop-blur-[2px] open:backdrop:animate-fade-in',
        size === 'full' ? 'h-[calc(100dvh-2rem)] max-w-none' : size === 'xl' ? 'max-w-4xl' : 'max-w-lg',
      )}
    >
      {open && (
        // Header and footer stay put; only the body scrolls on short screens.
        <div className={clsx('flex max-h-[calc(100dvh-2rem)] flex-col', size === 'full' && 'h-full')}>
          <header className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
            <h2 id={titleId} className="text-base font-semibold text-slate-900">
              {title}
            </h2>
            <button type="button" onClick={onClose} className="-mr-1.5 grid size-9 place-items-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800" aria-label="Tutup">
              <X className="size-4" aria-hidden />
            </button>
          </header>
          <div className={clsx('space-y-4 overflow-y-auto px-5 py-4', size === 'full' && 'flex-1 sm:px-8 sm:py-6')}>{children}</div>
          {footer && <footer className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3">{footer}</footer>}
        </div>
      )}
    </dialog>
  )
}
