import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, useFilterDraft } from '../../components/ui/Filters'
import { type FilterChip } from '../../components/ui/filterChips'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { AuditLog, Paginated } from '../../lib/api/types'
import { auditActionGroups, auditActionLabel, formatDate, formatDateTime } from '../../lib/format'

const ADVANCED = ['action', 'from', 'to'] as const

const actorLabel = (log: AuditLog) => log.actor?.name ?? (log.actor_type === 'system' ? 'Sistem' : 'Anonim')

/** Read-only audit trail. `endpoint` is /audit-logs (tenant) or /platform/audit-logs. */
export function AuditLogPage({ endpoint, title }: { endpoint: string; title: string }) {
  const [params, setParams] = useSearchParams()
  const [selected, setSelected] = useState<AuditLog | null>(null)
  const filters = { page: params.get('page'), action: params.get('action'), from: params.get('from'), to: params.get('to'), organization_id: params.get('organization_id') }
  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft

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
  const chips: FilterChip[] = [
    ...(params.get('action') ? [{ key: 'action', label: `Aksi: ${auditActionLabel(params.get('action')!)}`, clear: () => update({ action: '', page: '' }) }] : []),
    ...(params.get('from') ? [{ key: 'from', label: `Dari ${formatDate(params.get('from'))}`, clear: () => update({ from: '', page: '' }) }] : []),
    ...(params.get('to') ? [{ key: 'to', label: `Sampai ${formatDate(params.get('to'))}`, clear: () => update({ to: '', page: '' }) }] : []),
    ...(params.get('organization_id') ? [{ key: 'organization', label: 'Satu organisasi', clear: () => update({ organization_id: '', page: '' }) }] : []),
  ]

  const columns: Column<AuditLog>[] = [
    { key: 'time', header: 'Waktu', cell: (l) => <span className="whitespace-nowrap">{formatDateTime(l.created_at)}</span> },
    { key: 'actor', header: 'Pelaku', cell: actorLabel },
    { key: 'action', header: 'Aksi', cell: (l) => auditActionLabel(l.action) },
  ]

  return (
    <>
      <PageHeader title={title} />
      <Card>
        <FilterPanel>
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update({ ...draft, page: '' })} onClear={() => advanced.set(advanced.empty)}>
            <Select aria-label="Filter aksi" value={draft.action} onChange={(e) => advanced.set({ action: e.target.value })}>
              <option value="">Semua aksi</option>
              {auditActionGroups.map((g) => (
                <optgroup key={g.label} label={g.label}>
                  {Object.entries(g.actions).map(([code, label]) => (
                    <option key={code} value={code}>
                      {label}
                    </option>
                  ))}
                </optgroup>
              ))}
            </Select>
            <DateRangeFilter from={draft.from} to={draft.to} onChange={(from, to) => advanced.set({ from, to })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={() => setParams(new URLSearchParams())} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title={chips.length > 0 ? 'Tidak ada catatan yang cocok' : 'Belum ada catatan'} description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : undefined} />
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
            <Row label="Aksi" value={auditActionLabel(selected.action)} />
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
