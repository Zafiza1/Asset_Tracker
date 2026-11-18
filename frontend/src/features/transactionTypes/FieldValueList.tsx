import { Paperclip } from 'lucide-react'
import { downloadFile } from '../../lib/api/client'
import { toast } from '../../components/ui/toast'
import { errorMessage } from '../../lib/forms'
import type { AttachmentSummary } from './api'

interface Value {
  key: string
  label: string
  data_type: string | null
  value: unknown
  display: string | null
}

/** Values of an organization type's fields, as text; files open as downloads. */
export function FieldValueList({ values, variant = 'lines', emptyText }: { values: Value[]; variant?: 'lines' | 'grid'; emptyText?: (v: Value) => string | null }) {
  const shown = variant === 'lines' ? values.filter((v) => v.display !== null) : values
  if (shown.length === 0) return null

  const content = (v: Value) => {
    if (v.data_type === 'attachment' && v.value && typeof v.value === 'object') {
      const file = v.value as AttachmentSummary
      return (
        <button
          type="button"
          className="inline-flex max-w-full items-center gap-1 font-medium text-brand-700 hover:underline"
          onClick={() => downloadFile(`/transaction-attachments/${file.id}`, file.name).catch((e) => toast.error('Gagal mengunduh', errorMessage(e)))}
        >
          <Paperclip className="size-3.5 shrink-0" aria-hidden />
          <span className="truncate">{file.name}</span>
        </button>
      )
    }
    if (v.data_type === 'counterparty' && v.value && typeof v.value === 'object') {
      const c = v.value as { name?: string | null; contact?: string | null }
      return [c.name, c.contact].filter(Boolean).join(' · ') || '—'
    }
    return v.display ?? <span className="text-slate-400">{emptyText?.(v) ?? '—'}</span>
  }

  if (variant === 'grid') {
    return (
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
        {shown.map((v) => (
          <div key={v.key} className="contents">
            <dt className="text-slate-500">{v.label}</dt>
            <dd className="min-w-0 break-words text-slate-900">{content(v)}</dd>
          </div>
        ))}
      </dl>
    )
  }
  return (
    <>
      {shown.map((v) => (
        <p key={v.key} className="flex flex-wrap items-center gap-1 text-slate-700">
          <span className="text-slate-500">{v.label}:</span>
          <span className="min-w-0 font-medium text-slate-900">{content(v)}</span>
        </p>
      ))}
    </>
  )
}
