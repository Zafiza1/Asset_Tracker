import clsx from 'clsx'
import { AlertTriangle, HelpCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { errorMessage } from '../../lib/forms'
import { Button } from './Button'
import { Alert } from './Feedback'
import { Modal } from './Modal'

/**
 * Asks before an action that matters: deleting, deactivating, archiving, revoking, approving.
 * One question, what will happen, and two buttons — the safe one first. `tone="danger"` is for
 * actions that remove or block something; the confirm button then turns red.
 *
 * The dialog stays open while the request runs and when it fails, with the error inside it,
 * so the user never wonders whether the action went through.
 */
export function ConfirmDialog({
  open,
  title,
  children,
  confirmLabel,
  cancelLabel = 'Batal',
  tone = 'primary',
  loading = false,
  disabled = false,
  error,
  onConfirm,
  onClose,
}: {
  open: boolean
  title: string
  /** What the action does and whether it can be undone. */
  children: ReactNode
  /** Names the action, e.g. "Nonaktifkan" — never just "OK" or "Ya". */
  confirmLabel: string
  cancelLabel?: string
  tone?: 'primary' | 'danger'
  loading?: boolean
  disabled?: boolean
  /** A failed attempt (the mutation's error); shown above the text. */
  error?: unknown
  onConfirm: () => void
  onClose: () => void
}) {
  const Icon = tone === 'danger' ? AlertTriangle : HelpCircle
  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            {cancelLabel}
          </Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={loading} disabled={disabled} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {error ? <Alert tone="error">{errorMessage(error)}</Alert> : null}
      <div className="flex gap-3.5">
        <span className={clsx('grid size-10 shrink-0 place-items-center rounded-full', tone === 'danger' ? 'bg-red-50 text-red-600' : 'bg-brand-50 text-brand-700')} aria-hidden>
          <Icon className="size-5" />
        </span>
        <div className="min-w-0 flex-1 space-y-3 pt-0.5 text-sm text-slate-700">{children}</div>
      </div>
    </Modal>
  )
}
