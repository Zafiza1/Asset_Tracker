import clsx from 'clsx'
import type { ReactNode } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Modal } from '../../components/ui/Modal'
import type { AuditLog } from '../../lib/api/types'
import { auditActionLabel } from '../../lib/format'
import { actorName, auditCategories, auditCategoryOf, auditFieldLabel, auditModuleOf, auditValue, entityTypeLabel, formatFullDateTime } from './auditMeta'

/** One level of nested objects (e.g. asset attributes) becomes "Atribut · Merek" rows. */
function flatten(data: Record<string, unknown> | null): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(data ?? {})) {
    if (v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length > 0) {
      for (const [sub, sv] of Object.entries(v)) out[`${k}.${sub}`] = sv
    } else {
      out[k] = v
    }
  }
  return out
}

const fieldName = (key: string) => {
  const [parent, child] = key.split('.')
  return child ? `${auditFieldLabel(parent)} · ${child.replace(/_/g, ' ')}` : auditFieldLabel(parent)
}

const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

function Value({ value, tone }: { value: unknown; tone?: 'old' | 'new' }) {
  const text = auditValue(value)
  return (
    <span
      className={clsx(
        'whitespace-pre-wrap break-words',
        text === '—' && 'text-slate-400',
        tone === 'old' && text !== '—' && 'text-red-700 line-through decoration-red-300',
        tone === 'new' && text !== '—' && 'font-medium text-green-800',
      )}
    >
      {text}
    </span>
  )
}

function Changes({ log }: { log: AuditLog }) {
  const before = flatten(log.before)
  const after = flatten(log.after)
  const hasBefore = log.before && Object.keys(before).length > 0
  const hasAfter = log.after && Object.keys(after).length > 0
  if (!hasBefore && !hasAfter) {
    return <p className="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-500">Aktivitas ini tidak mencatat perubahan nilai.</p>
  }

  // Only one side: a record of what was created (or what was there), not a comparison.
  if (!hasBefore || !hasAfter) {
    const data = hasAfter ? after : before
    return (
      <Table head={['Data', hasAfter ? 'Nilai tercatat' : 'Nilai sebelumnya']}>
        {Object.entries(data).map(([k, v]) => (
          <tr key={k}>
            <th scope="row" className="w-2/5 px-3 py-2 text-left align-top font-medium text-slate-600">
              {fieldName(k)}
            </th>
            <td className="px-3 py-2 align-top text-slate-800">
              <Value value={v} />
            </td>
          </tr>
        ))}
      </Table>
    )
  }

  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])]
  return (
    <Table head={['Data', 'Sebelum', 'Sesudah']}>
      {keys.map((k) => {
        const changed = !same(before[k], after[k])
        return (
          <tr key={k} className={changed ? 'bg-amber-50/40' : undefined}>
            <th scope="row" className="w-1/4 px-3 py-2 text-left align-top font-medium text-slate-600">
              {fieldName(k)}
            </th>
            <td className="px-3 py-2 align-top">
              <Value value={before[k]} tone={changed ? 'old' : undefined} />
            </td>
            <td className="px-3 py-2 align-top">
              <Value value={after[k]} tone={changed ? 'new' : undefined} />
            </td>
          </tr>
        )
      })}
    </Table>
  )
}

function Table({ head, children }: { head: string[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
      <table className="w-full table-fixed divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50">
          <tr>
            {head.map((h) => (
              <th key={h} scope="col" className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">{children}</tbody>
      </table>
    </div>
  )
}

function Item({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-slate-800">{children}</dd>
    </div>
  )
}

export function AuditLogDetail({ log, onClose }: { log: AuditLog | null; onClose: () => void }) {
  const category = log ? auditCategories[auditCategoryOf(log.action)] : null
  const metadata = Object.entries(log?.metadata ?? {})

  return (
    <Modal open={log !== null} size="xl" title="Detail aktivitas" onClose={onClose}>
      {log && category && (
        <>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="text-lg font-semibold text-slate-900">{auditActionLabel(log.action)}</p>
              <p className="font-mono text-xs text-slate-400">{log.action}</p>
            </div>
            <div className="flex flex-wrap gap-1.5">
              <Badge tone={category.tone}>{category.label}</Badge>
              {auditModuleOf(log.action) && <Badge>{auditModuleOf(log.action)!.label}</Badge>}
            </div>
          </div>

          <dl className="grid gap-4 rounded-lg bg-slate-50 p-4 sm:grid-cols-2">
            <Item label="Pelaku">
              <span className="font-medium">{actorName(log)}</span>
              {log.actor?.email && <span className="block text-slate-500">{log.actor.email}</span>}
            </Item>
            <Item label="Waktu">{formatFullDateTime(log.created_at)}</Item>
            <Item label="Objek">
              {log.entity_label || log.entity_type ? (
                <>
                  {entityTypeLabel(log.entity_type) && <span className="text-slate-500">{entityTypeLabel(log.entity_type)}: </span>}
                  {log.entity_label?.code && <span className="font-mono text-xs font-semibold">{log.entity_label.code}</span>}
                  {log.entity_label?.code && log.entity_label?.name && ' — '}
                  {log.entity_label?.name && <span className="font-medium">{log.entity_label.name}</span>}
                  {!log.entity_label && log.entity_id && <span className="font-mono text-xs">{log.entity_id}</span>}
                </>
              ) : (
                <span className="text-slate-400">—</span>
              )}
            </Item>
            <Item label="Alamat IP">{log.ip ? <span className="font-mono text-xs">{log.ip}</span> : <span className="text-slate-400">Tidak tercatat</span>}</Item>
          </dl>

          <section className="space-y-2">
            <h3 className="text-sm font-semibold text-slate-900">Perubahan</h3>
            <Changes log={log} />
          </section>

          {metadata.length > 0 && (
            <section className="space-y-2">
              <h3 className="text-sm font-semibold text-slate-900">Keterangan tambahan</h3>
              <dl className="grid gap-3 sm:grid-cols-2">
                {metadata.map(([k, v]) => (
                  <Item key={k} label={auditFieldLabel(k)}>
                    <Value value={v} />
                  </Item>
                ))}
              </dl>
            </section>
          )}

          <details className="text-xs text-slate-500">
            <summary className="cursor-pointer select-none font-medium hover:text-slate-700">Info teknis</summary>
            <dl className="mt-2 grid gap-2 sm:grid-cols-2">
              <Item label="ID objek">{log.entity_id ? <span className="font-mono text-xs">{log.entity_id}</span> : '—'}</Item>
              <Item label="ID permintaan">{log.request_id ? <span className="font-mono text-xs">{log.request_id}</span> : '—'}</Item>
              {log.user_agent && (
                <div className="sm:col-span-2">
                  <Item label="Perangkat / browser">
                    <span className="text-xs">{log.user_agent}</span>
                  </Item>
                </div>
              )}
            </dl>
          </details>
        </>
      )}
    </Modal>
  )
}
