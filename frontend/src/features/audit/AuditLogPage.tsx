import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { AuditLog, Paginated } from '../../lib/api/types'
import { formatDateTime } from '../../lib/format'

const actorLabel = (log: AuditLog) => log.actor?.name ?? (log.actor_type === 'system' ? 'Sistem' : 'Anonim')

/** Read-only audit trail. `endpoint` is /audit-logs (tenant) or /platform/audit-logs. */
export function AuditLogPage({ endpoint, title }: { endpoint: string; title: string }) {
  const [params, setParams] = useSearchParams()
  const [action, setAction] = useState(params.get('action') ?? '')
  const [selected, setSelected] = useState<AuditLog | null>(null)
  const filters = { page: params.get('page'), action: params.get('action'), from: params.get('from'), to: params.get('to'), organization_id: params.get('organization_id') }

  const query = useQuery({
    queryKey: ['audit', endpoint, filters],
    queryFn: () => api.get<Paginated<AuditLog>>(`${endpoint}${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    setParams(merged)
  }

  const columns: Column<AuditLog>[] = [
    { key: 'time', header: 'Waktu', cell: (l) => <span className="whitespace-nowrap">{formatDateTime(l.created_at)}</span> },
    { key: 'actor', header: 'Pelaku', cell: actorLabel },
    { key: 'action', header: 'Aksi', cell: (l) => <span className="font-mono text-xs">{l.action}</span> },
    { key: 'entity', header: 'Entitas', cell: (l) => (l.entity_type ? `${l.entity_type} ${l.entity_id?.slice(0, 8) ?? ''}` : '—'), hideOnMobile: true },
    { key: 'ip', header: 'IP', cell: (l) => l.ip ?? '—', hideOnMobile: true },
  ]

  return (
    <>
      <PageHeader title={title} description="Catatan append-only atas aktivitas penting. Tidak dapat diubah atau dihapus." />
      <Card>
        <form
          role="search"
          className="flex flex-wrap items-end gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            update({ action, page: '' })
          }}
        >
          <label className="min-w-48 flex-1 text-sm">
            <span className="mb-1 block text-slate-600">Aksi</span>
            <Input value={action} onChange={(e) => setAction(e.target.value)} placeholder="mis. user.* atau auth.login" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Dari</span>
            <Input type="date" value={params.get('from') ?? ''} onChange={(e) => update({ from: e.target.value, page: '' })} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Sampai</span>
            <Input type="date" value={params.get('to') ?? ''} onChange={(e) => update({ to: e.target.value, page: '' })} />
          </label>
          <Button type="submit" variant="secondary">
            Terapkan
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Tidak ada catatan" description="Tidak ada aktivitas yang cocok dengan filter." />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={setSelected} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      <Modal open={selected !== null} title="Detail audit" onClose={() => setSelected(null)}>
        {selected && (
          <dl className="space-y-2 text-sm">
            <Row label="Waktu" value={formatDateTime(selected.created_at)} />
            <Row label="Pelaku" value={actorLabel(selected)} />
            <Row label="Aksi" value={selected.action} />
            <Row label="Entitas" value={selected.entity_type ? `${selected.entity_type} · ${selected.entity_id}` : '—'} />
            <Row label="ID permintaan" value={selected.request_id ?? '—'} />
            {(['before', 'after', 'metadata'] as const).map((k) =>
              selected[k] ? (
                <div key={k}>
                  <dt className="text-slate-500">{{ before: 'Sebelum', after: 'Sesudah', metadata: 'Metadata' }[k]}</dt>
                  <dd>
                    <pre className="mt-1 max-h-48 overflow-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(selected[k], null, 2)}</pre>
                  </dd>
                </div>
              ) : null,
            )}
          </dl>
        )}
      </Modal>
    </>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="grid grid-cols-3 gap-2">
      <dt className="text-slate-500">{label}</dt>
      <dd className="col-span-2 break-all text-slate-800">{value}</dd>
    </div>
  )
}
