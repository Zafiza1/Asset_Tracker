import { isApprovalPending } from '../../lib/api/client'

/** `pending`: the action was accepted but waits for someone else (e.g. an approval). */
export type ToastTone = 'success' | 'error' | 'warning' | 'info' | 'pending'

export interface ToastItem {
  id: number
  tone: ToastTone
  title: string
  description?: string
  /** In-app link shown under the text, e.g. "Lihat persetujuan". */
  action?: { label: string; to: string }
  /** Milliseconds on screen; errors and toasts with an action stay longer by default. */
  duration: number
}

type ToastInput = Omit<ToastItem, 'id' | 'duration'> & { duration?: number }

/** More than this at once is noise: the oldest toast leaves when a new one arrives. */
const MAX_VISIBLE = 4

let items: ToastItem[] = []
let nextId = 1
const listeners = new Set<() => void>()

function emit(next: ToastItem[]) {
  items = next
  listeners.forEach((l) => l())
}

function show(input: ToastInput): number {
  const id = nextId++
  const duration = input.duration ?? (input.tone === 'error' || input.action ? 8000 : 5000)
  emit([...items, { ...input, id, duration }].slice(-MAX_VISIBLE))
  return id
}

/**
 * Feedback after an action, shown by <Toaster /> wherever the user is. A module-level store, so
 * mutations can report their outcome without a provider (and a modal can close before the
 * message appears).
 */
export const toast = {
  show,
  success: (title: string, description?: string) => show({ tone: 'success', title, description }),
  error: (title: string, description?: string) => show({ tone: 'error', title, description }),
  warning: (title: string, description?: string) => show({ tone: 'warning', title, description }),
  info: (title: string, description?: string) => show({ tone: 'info', title, description }),
  /**
   * Success message for a change that may need approval: when the API answered "submitted for
   * approval" (202) nothing was changed yet, and the app already announces that, so claiming
   * success here would be wrong.
   */
  saved: (response: unknown, title: string, description?: string) => {
    if (!isApprovalPending(response)) show({ tone: 'success', title, description })
  },
  dismiss: (id: number) => emit(items.filter((t) => t.id !== id)),
  clear: () => emit([]),
}

export const toastStore = {
  subscribe: (listener: () => void) => {
    listeners.add(listener)
    return () => {
      listeners.delete(listener)
    }
  },
  getSnapshot: () => items,
}
