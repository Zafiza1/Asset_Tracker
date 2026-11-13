import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Inbox, Info, Loader2, RotateCw, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { ApiError } from '../../lib/api/client'
import { Button } from './Button'

/** Short wait inside a small area (a modal body, a button row). Lists and pages use a skeleton instead. */
export function Spinner({ label = 'Memuat…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
      <Loader2 className="size-5 animate-spin text-brand-600" aria-hidden />
      {label}
    </div>
  )
}

/** Placeholder block that reserves the space of content still loading (no layout jump). */
export function Skeleton({ className }: { className?: string }) {
  return <div className={clsx('animate-pulse rounded-md bg-slate-200/70', className)} aria-hidden />
}

/** Bar widths cycled per column, so a skeleton table reads as rows of different text, not a grid of blocks. */
const BAR_WIDTHS = ['w-3/4', 'w-1/2', 'w-2/3', 'w-2/5', 'w-3/5']

/** A table still loading: header strip and rows of bars where the data will be. */
export function TableSkeleton({ rows = 8, columns = 5, label = 'Memuat data…' }: { rows?: number; columns?: number; label?: string }) {
  return (
    <div role="status" aria-busy="true">
      <span className="sr-only">{label}</span>
      <div className="flex gap-4 border-b border-slate-200 bg-slate-100/70 px-4 py-3" aria-hidden>
        {Array.from({ length: columns }, (_, c) => (
          <div key={c} className={clsx('flex-1', c >= 3 && 'hidden md:block')}>
            <Skeleton className="h-3 w-16 bg-slate-300/70" />
          </div>
        ))}
      </div>
      <div className="divide-y divide-slate-100" aria-hidden>
        {Array.from({ length: rows }, (_, r) => (
          <div key={r} className="flex items-center gap-4 px-4 py-3.5">
            {Array.from({ length: columns }, (_, c) => (
              <div key={c} className={clsx('flex-1', c >= 3 && 'hidden md:block')}>
                <Skeleton className={clsx('h-4', BAR_WIDTHS[(r + c) % BAR_WIDTHS.length])} />
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}

/** A detail page still loading: title, a row of fact cards and two panels. */
export function DetailSkeleton({ label = 'Memuat data…' }: { label?: string }) {
  return (
    <div role="status" aria-busy="true">
      <span className="sr-only">{label}</span>
      <div aria-hidden>
        <Skeleton className="h-8 w-72 max-w-full" />
        <Skeleton className="mt-2 h-4 w-48" />
        <div className="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-[4.5rem] rounded-xl" />
          ))}
        </div>
        <div className="mt-4 grid gap-4 xl:grid-cols-5">
          <Skeleton className="h-80 rounded-xl xl:col-span-2" />
          <Skeleton className="h-80 rounded-xl xl:col-span-3" />
        </div>
      </div>
    </div>
  )
}

/** A form or settings card still loading: label + field pairs. */
export function FormSkeleton({ fields = 4, label = 'Memuat data…' }: { fields?: number; label?: string }) {
  return (
    <div role="status" aria-busy="true" className="space-y-5 p-5">
      <span className="sr-only">{label}</span>
      {Array.from({ length: fields }, (_, i) => (
        <div key={i} aria-hidden>
          <Skeleton className="h-3.5 w-28" />
          <Skeleton className="mt-2 h-10 w-full max-w-xl" />
        </div>
      ))}
    </div>
  )
}

/**
 * What a list shows when it has nothing: a picture, what that means, and the next step.
 * `action` is the way forward (add the first record, clear the filters); leave it out only
 * when the user can do nothing about it.
 */
export function EmptyState({ title, description, action, icon: Icon = Inbox }: { title: string; description?: string; action?: ReactNode; icon?: LucideIcon }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
      {/* Illustration: the icon on a soft disc with two small accents, the same for every empty list. */}
      <span className="relative mb-2 grid size-20 place-items-center" aria-hidden>
        <span className="absolute inset-0 rounded-full bg-brand-50" />
        <span className="absolute inset-2.5 rounded-full bg-white shadow-card ring-1 ring-brand-100" />
        <span className="absolute right-0.5 top-1.5 size-2.5 rounded-full bg-brand-200" />
        <span className="absolute bottom-2 left-0 size-1.5 rounded-full bg-brand-300" />
        <Icon className="relative size-7 text-brand-600" />
      </span>
      <p className="font-display text-base font-semibold text-slate-900">{title}</p>
      {description && <p className="max-w-md text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-3 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  )
}

type Tone = 'error' | 'success' | 'info' | 'warning'
const tones: Record<Tone, { box: string; icon: string; Icon: typeof Info }> = {
  error: { box: 'bg-red-50 text-red-800 ring-red-200', icon: 'text-red-600', Icon: AlertTriangle },
  warning: { box: 'bg-amber-50 text-amber-900 ring-amber-200', icon: 'text-amber-600', Icon: AlertTriangle },
  success: { box: 'bg-green-50 text-green-800 ring-green-200', icon: 'text-green-600', Icon: CheckCircle2 },
  info: { box: 'bg-brand-50 text-brand-800 ring-brand-200', icon: 'text-brand-600', Icon: Info },
}

export function Alert({ tone = 'info', title, children }: { tone?: Tone; title?: string; children?: ReactNode }) {
  const { box, icon, Icon } = tones[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={clsx('flex gap-3 rounded-lg p-3.5 text-sm ring-1 ring-inset', box)}>
      <Icon className={clsx('mt-0.5 size-4 shrink-0', icon)} aria-hidden />
      <div className="min-w-0">
        {title && <p className="font-semibold">{title}</p>}
        {children}
      </div>
    </div>
  )
}

/** Renders an API/network error with its request id for support; `onRetry` adds "Coba lagi". */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof ApiError ? error.message : 'Terjadi kesalahan yang tidak terduga.'
  const requestId = error instanceof ApiError ? error.requestId : null
  return (
    <div className="p-4">
      <Alert tone="error" title="Gagal memuat data">
        <p>{message}</p>
        {requestId && <p className="mt-1 text-xs opacity-75">ID permintaan: {requestId}</p>}
        {onRetry && (
          <Button className="mt-3" size="sm" variant="secondary" onClick={onRetry}>
            <RotateCw className="size-3.5" aria-hidden /> Coba lagi
          </Button>
        )}
      </Alert>
    </div>
  )
}
