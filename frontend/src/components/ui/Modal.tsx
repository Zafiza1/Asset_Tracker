import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ReactNode } from 'react'

/** Accessible dialog on the native <dialog> element (modal focus handling and Esc built in). */
export function Modal({ open, title, onClose, children, footer }: { open: boolean; title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal?.()
    if (!open && dialog.open) dialog.close?.()
  }, [open])

  return (
    <dialog ref={ref} onClose={onClose} aria-labelledby={titleId} className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg p-0 shadow-xl backdrop:bg-slate-900/40">
      {open && (
        <div>
          <header className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 id={titleId} className="font-semibold text-slate-900">
              {title}
            </h2>
            <button type="button" onClick={onClose} className="rounded p-1 text-slate-500 hover:bg-slate-100" aria-label="Tutup">
              <X className="size-4" aria-hidden />
            </button>
          </header>
          <div className="space-y-4 px-4 py-4">{children}</div>
          {footer && <footer className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">{footer}</footer>}
        </div>
      )}
    </dialog>
  )
}
