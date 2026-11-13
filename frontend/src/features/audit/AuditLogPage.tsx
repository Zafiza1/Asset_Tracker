import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Eye, FileClock, SearchX } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { IconButton } from '../../components/ui/IconButton'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { AuditActor, AuditLog, Paginated } from '../../lib/api/types'
import { auditActionLabel, formatDate } from '../../lib/format'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { AuditLogDetail } from './AuditLogDetail'
import { actorName, auditCategories, auditCategoryOf, auditCodesMatching, auditCodesOf, auditModuleOf, auditModules, entityTypeLabel, type AuditCategory } from './auditMeta'
import { Button } from '../../components/ui/Button'

const ADVANCED = ['actor_user_id', 'module', 'category', 'from', 'to'] as const

const timeOnly = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

function rangeError(from: string, to: string): string | null {
  return from && to && from > to ? 'Tanggal "Dari" tidak boleh setelah tanggal "Sampai".' : null
}

/** Read-only audit trail. `endpoint` is /audit-logs (tenant) or /platform/audit-logs. */
export function AuditLogPage({ endpoint, title }: { endpoint: string; title: string }) {
  const [params, setParams] = usePersistedSearchParams(endpoint)
  const [selected, setSelected] = useState<AuditLog | null>(null)
  const [search, setSearch] = useState(params.get('search') ?? '')
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters(endpoint)

  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft
  const draftError = rangeError(draft.from, draft.to)

  const applied = Object.fromEntries(['page', 'per_page', 'search', 'action', 'organization_id', ...ADVANCED].map((k) => [k, params.get(k) ?? ''])) as Record<string, string>
  const category = (applied.category in auditCategories ? applied.category : '') as AuditCategory | ''

  const query = useQuery({
    queryKey: ['audit', endpoint, applied],
    queryFn: () =>
      api.get<Paginated<AuditLog>>(
        `${endpoint}${qs({
          page: applied.page,
          per_page: applied.per_page,
          search: applied.search,
          search_actions: applied.search ? auditCodesMatching(applied.search).join(',') : '',
          action: applied.action,
          actor_user_id: applied.actor_user_id,
          module: applied.module,
          actions: category ? auditCodesOf(category).join(',') : '',
          from: applied.from,
          to: applied.to,
          organization_id: applied.organization_id,
        })}`,
      ),
    placeholderData: keepPreviousData,
  })

  // The tenant endpoint lists who appears in the trail; the (retired) platform one has no such list.
  const actors = useQuery({
    queryKey: ['audit-actors', endpoint],
    queryFn: () => api.get<{ data: AuditActor[] }>(`${endpoint}/actors`).then((r) => r.data),
    enabled: endpoint === '/audit-logs',
    staleTime: 60_000,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) {
        merged.set(k, v)
        persistFilter(k, v)
      } else {
        merged.delete(k)
        unPersistFilter(k)
      }
    }
    setParams(merged)
  }
  const resetAll = () => {
    setSearch('')
    resetFilters()
    // Rows per page is a display preference, not a filter: keep it.
    setParams(applied.per_page ? new URLSearchParams({ per_page: applied.per_page }) : new URLSearchParams())
    if (applied.per_page) persistFilter('per_page', applied.per_page)
  }

  const actorOf = (id: string) => actors.data?.find((a) => a.id === id)
  const moduleLabel = auditModules.find((m) => m.key === applied.module)?.label
  const chips: FilterChip[] = [
    ...searchChip(applied.search || null, () => (setSearch(''), update({ search: '', page: '' }))),
    ...(applied.actor_user_id ? [{ key: 'actor', label: `Pelaku: ${actorOf(applied.actor_user_id)?.name ?? '…'}`, clear: () => update({ actor_user_id: '', page: '' }) }] : []),
    ...(applied.module ? [{ key: 'module', label: `Modul: ${moduleLabel ?? applied.module}`, clear: () => update({ module: '', page: '' }) }] : []),
    ...(category ? [{ key: 'category', label: `Kategori: ${auditCategories[category].label}`, clear: () => update({ category: '', page: '' }) }] : []),
    ...(applied.action ? [{ key: 'action', label: `Aktivitas: ${auditActionLabel(applied.action)}`, clear: () => update({ action: '', page: '' }) }] : []),
    ...(applied.from ? [{ key: 'from', label: `Dari ${formatDate(applied.from)}`, clear: () => update({ from: '', page: '' }) }] : []),
    ...(applied.to ? [{ key: 'to', label: `Sampai ${formatDate(applied.to)}`, clear: () => update({ to: '', page: '' }) }] : []),
    ...(applied.organization_id ? [{ key: 'organization', label: 'Satu organisasi', clear: () => update({ organization_id: '', page: '' }) }] : []),
  ]

  const columns: Column<AuditLog>[] = [
    {
      key: 'time',
      header: 'Waktu',
      className: 'w-36',
      cell: (l) => (
        <div className="leading-tight">
          <div className="font-medium text-slate-800">{formatDate(l.created_at)}</div>
          <div className="text-xs tabular-nums text-slate-500">{timeOnly.format(new Date(l.created_at))}</div>
        </div>
      ),
    },
    {
      key: 'actor',
      header: 'Pelaku',
      title: (l) => [actorName(l), l.actor?.email].filter(Boolean).join(' · '),
      cell: (l) => (
        <div className="min-w-0 leading-tight">
          <div className={l.actor ? 'truncate font-medium text-slate-900' : 'truncate italic text-slate-500'}>{actorName(l)}</div>
          {l.actor?.email && <div className="truncate text-xs text-slate-500">{l.actor.email}</div>}
        </div>
      ),
    },
    {
      key: 'action',
      header: 'Aktivitas',
      title: (l) => auditActionLabel(l.action),
      cell: (l) => {
        const c = auditCategories[auditCategoryOf(l.action)]
        return (
          <div className="min-w-0 space-y-1 leading-tight">
            <div className="truncate text-slate-800">{auditActionLabel(l.action)}</div>
            <Badge tone={c.tone}>{c.label}</Badge>
          </div>
        )
      },
    },
    { key: 'module', header: 'Modul', className: 'w-36', hideOnMobile: true, cell: (l) => <span className="text-slate-600">{auditModuleOf(l.action)?.label ?? '—'}</span> },
    {
      key: 'object',
      header: 'Objek',
      hideOnMobile: true,
      title: (l) => [l.entity_label?.code, l.entity_label?.name].filter(Boolean).join(' — ') || null,
      cell: (l) =>
        l.entity_label ? (
          <div className="min-w-0 leading-tight">
            <div className="truncate text-slate-800">{l.entity_label.name ?? l.entity_label.code}</div>
            {l.entity_label.name && l.entity_label.code && <div className="truncate font-mono text-xs text-slate-500">{l.entity_label.code}</div>}
          </div>
        ) : (
          <span className="text-slate-400">{entityTypeLabel(l.entity_type) ?? '—'}</span>
        ),
    },
    {
      key: 'detail',
      header: 'Detail',
      className: 'w-20 text-right! pr-3!',
      cell: (l) => (
        <IconButton label={`Lihat detail: ${auditActionLabel(l.action)}`} tooltip="Lihat detail" onClick={() => setSelected(l)}>
          <Eye className="size-4" aria-hidden />
        </IconButton>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={title} description="Riwayat aktivitas di organisasi, terbaru di atas." />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search: search.trim(), page: '' })}
            onClear={() => applied.search && update({ search: '', page: '' })}
            placeholder="Cari nama pengguna, aktivitas, atau kode/nama objek"
            label="Cari di audit trail"
          />
          <AdvancedFilters
            defaultOpen={advanced.activeCount > 0}
            activeCount={advanced.activeCount}
            dirty={advanced.dirty}
            error={draftError}
            onApply={() => update({ ...draft, page: '' })}
            onClear={() => advanced.set(advanced.empty)}
            onReset={() => update({ ...advanced.empty, page: '' })}
          >
            <Select aria-label="Filter pelaku" value={draft.actor_user_id} onChange={(e) => advanced.set({ actor_user_id: e.target.value })} disabled={endpoint !== '/audit-logs' || actors.isLoading}>
              <option value="">Pelaku: semua</option>
              {actors.data?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name} ({a.email})
                </option>
              ))}
            </Select>
            <Select aria-label="Filter modul" value={draft.module} onChange={(e) => advanced.set({ module: e.target.value })}>
              <option value="">Modul: semua</option>
              {auditModules.map((m) => (
                <option key={m.key} value={m.key}>
                  {m.label}
                </option>
              ))}
            </Select>
            <Select aria-label="Filter kategori aktivitas" value={draft.category} onChange={(e) => advanced.set({ category: e.target.value })}>
              <option value="">Kategori: semua</option>
              {Object.entries(auditCategories).map(([key, c]) => (
                <option key={key} value={key}>
                  {c.label}
                </option>
              ))}
            </Select>
            <DateRangeFilter from={draft.from} to={draft.to} onChange={(from, to) => advanced.set({ from, to })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={5} label="Memuat audit trail…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={chips.length > 0 ? SearchX : FileClock}
            title={chips.length > 0 ? 'Tidak ada catatan yang cocok' : 'Belum ada catatan'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : 'Setiap perubahan data, siapa yang melakukannya, dan kapan, akan tercatat di sini.'}
            action={
              chips.length > 0 && (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              )
            }
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={setSelected} className="min-w-[44rem]" />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} onPerPage={(n) => update({ per_page: String(n), page: '' })} />
          </>
        )}
      </Card>
      <AuditLogDetail log={selected} onClose={() => setSelected(null)} />
    </>
  )
}
