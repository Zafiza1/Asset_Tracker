import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Clock, Info, X, XCircle, type LucideIcon } from 'lucide-react'
import { useEffect, useRef, useSyncExternalStore } from 'react'
import { Link } from 'react-router'
import { toast, toastStore, type ToastItem, type ToastTone } from './toast'

const tones: Record<ToastTone, { Icon: LucideIcon; icon: string; bar: string }> = {
  success: { Icon: CheckCircle2, icon: 'text-green-600', bar: 'bg-green-500' },
  error: { Icon: XCircle, icon: 'text-red-600', bar: 'bg-red-500' },
  warning: { Icon: AlertTriangle, icon: 'text-amber-600', bar: 'bg-amber-500' },
  pending: { Icon: Clock, icon: 'text-amber-600', bar: 'bg-amber-500' },
  info: { Icon: Info, icon: 'text-brand-600', bar: 'bg-brand-500' },
}

/**
 * The one place toasts appear: bottom of the screen on phones, bottom-right on larger screens.
 * Mounted once at the root of the router. Each toast carries an icon and text (never color
 * alone), closes by itself, and waits while the pointer or keyboard focus is on it.
 */
export function Toaster() {
  const items = useSyncExternalStore(toastStore.subscribe, toastStore.getSnapshot)

  return (
    <div
      aria-label="Notifikasi"
      className="pointer-events-none fixed inset-x-4 bottom-4 z-[70] flex flex-col gap-2 sm:left-auto sm:right-6 sm:w-96"
    >
      {items.map((item) => (
        <ToastCard key={item.id} item={item} />
      ))}
    </div>
  )
}

function ToastCard({ item }: { item: ToastItem }) {
  const { Icon, icon, bar } = tones[item.tone]
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const stop = () => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
  }
  const start = () => {
    stop()
    timer.current = setTimeout(() => toast.dismiss(item.id), item.duration)
  }
  useEffect(() => {
    start()
    return stop
    // eslint-disable-next-line react-hooks/exhaustive-deps -- one timer per toast, restarted only by hover/focus
  }, [item.id])

  return (
    <div
      // Errors interrupt a screen reader; everything else waits for a pause.
      role={item.tone === 'error' ? 'alert' : 'status'}
      onMouseEnter={stop}
      onMouseLeave={start}
      onFocus={stop}
      onBlur={start}
      className="pointer-events-auto relative flex animate-toast-in gap-3 overflow-hidden rounded-xl bg-white p-4 pl-5 text-sm shadow-overlay ring-1 ring-slate-900/10"
    >
      <span className={clsx('absolute inset-y-0 left-0 w-1', bar)} aria-hidden />
      <Icon className={clsx('mt-0.5 size-5 shrink-0', icon)} aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="font-semibold text-slate-900">{item.title}</p>
        {item.description && <p className="mt-0.5 break-words text-slate-600">{item.description}</p>}
        {item.action && (
          <Link to={item.action.to} onClick={() => toast.dismiss(item.id)} className="mt-2 inline-block font-semibold text-brand-700 hover:text-brand-800">
            {item.action.label}
          </Link>
        )}
      </div>
      <button
        type="button"
        onClick={() => toast.dismiss(item.id)}
        className="-mr-1 -mt-1 grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
        aria-label="Tutup notifikasi"
      >
        <X className="size-4" aria-hidden />
      </button>
    </div>
  )
}
