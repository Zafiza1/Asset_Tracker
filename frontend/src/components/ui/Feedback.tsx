import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Inbox, Info, Loader2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { ApiError } from '../../lib/api/client'

export function Spinner({ label = 'Memuat…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
      <Loader2 className="size-5 animate-spin" aria-hidden />
      {label}
    </div>
  )
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
      <Inbox className="size-8 text-slate-400" aria-hidden />
      <p className="font-medium text-slate-700">{title}</p>
      {description && <p className="max-w-md text-sm text-slate-500">{description}</p>}
      {action}
    </div>
  )
}

type Tone = 'error' | 'success' | 'info' | 'warning'
const tones: Record<Tone, { box: string; Icon: typeof Info }> = {
  error: { box: 'bg-red-50 text-red-800 ring-red-200', Icon: AlertTriangle },
  warning: { box: 'bg-amber-50 text-amber-800 ring-amber-200', Icon: AlertTriangle },
  success: { box: 'bg-green-50 text-green-800 ring-green-200', Icon: CheckCircle2 },
  info: { box: 'bg-brand-50 text-brand-800 ring-brand-200', Icon: Info },
}

export function Alert({ tone = 'info', title, children }: { tone?: Tone; title?: string; children?: ReactNode }) {
  const { box, Icon } = tones[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={clsx('flex gap-3 rounded-md p-3 text-sm ring-1 ring-inset', box)}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div>
        {title && <p className="font-medium">{title}</p>}
        {children}
      </div>
    </div>
  )
}

/** Renders an API/network error with its request id for support. */
export function ErrorState({ error }: { error: unknown }) {
  const message = error instanceof ApiError ? error.message : 'Terjadi kesalahan yang tidak terduga.'
  const requestId = error instanceof ApiError ? error.requestId : null
  return (
    <div className="p-4">
      <Alert tone="error" title="Gagal memuat data">
        <p>{message}</p>
        {requestId && <p className="mt-1 text-xs opacity-75">ID permintaan: {requestId}</p>}
      </Alert>
    </div>
  )
}
