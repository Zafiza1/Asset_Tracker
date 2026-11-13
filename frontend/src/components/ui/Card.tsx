import clsx from 'clsx'
import { useEffect, type ReactNode } from 'react'

export function Card({ title, actions, children, className }: { title?: string; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={clsx('rounded-xl bg-white shadow-card ring-1 ring-slate-200/80', className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  )
}

/**
 * Title row of every page. `badges` sit beside the title (e.g. a status), `description` and
 * `meta` below it. Actions line up on the right on wide screens and wrap under the title,
 * left-aligned, on narrow ones. Also names the browser tab after the page.
 */
export function PageHeader({ title, description, actions, badges, meta }: { title: string; description?: ReactNode; actions?: ReactNode; badges?: ReactNode; meta?: ReactNode }) {
  // The browser tab and history entries name the page, not just the app.
  useEffect(() => {
    if (!title) return
    const previous = document.title
    document.title = `${title} · Asset Tracker`
    return () => {
      document.title = previous
    }
  }, [title])

  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
          <h1 className="min-w-0 break-words text-2xl font-semibold text-slate-900 sm:text-[1.75rem]">{title}</h1>
          {badges && <div className="flex flex-wrap items-center gap-1.5">{badges}</div>}
        </div>
        {description && <p className="mt-1 text-[0.9375rem] text-slate-600">{description}</p>}
        {meta && <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">{meta}</div>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2 sm:justify-end">{actions}</div>}
    </div>
  )
}
