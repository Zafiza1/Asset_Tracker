import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight, SearchX } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { PillTabs } from '../../components/ui/Tabs'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { formatDate } from '../../lib/format'
import { outgoingNatureLabel, transactionTypeLabel, type AssetTransaction, type OutgoingNature, type TransactionType } from './api'
import { TransactionChanges } from './TransactionChanges'
import { RunList } from './RunList'
import { runsApi } from '../transactionTypes/api'

const FILTERS = ['page', 'per_page', 'search', 'type', 'outgoing_nature', 'not_returned', 'date_from', 'date_to', 'sort', 'columns'] as const
const DEFAULT_PER_PAGE = 25
const ADVANCED = ['type', 'outgoing_nature', 'date_from', 'date_to'] as const

/**
 * List tabs; counts come from GET /transactions/counts under the other filters. "Sedang diproses"
 * (proses=1) lists the transactions with a flow still on their way (waiting for an approval or an
 * input): they are recorded here only once their action has run, so they would not show otherwise.
 */
type Tab = '' | 'outgoing' | 'incoming' | 'not_returned' | 'proses'
const TABS: { value: Exclude<Tab, 'proses'>; label: string; count: 'all' | Exclude<Tab, '' | 'proses'> }[] = [
  { value: '', label: 'Semua', count: 'all' },
  { value: 'outgoing', label: 'Keluar', count: 'outgoing' },
  { value: 'incoming', label: 'Masuk', count: 'incoming' },
  { value: 'not_returned', label: 'Belum kembali', count: 'not_returned' },
]

/** Empty list of a tab (without search or other filters): says what the tab holds, not "no data at all". */
const TAB_EMPTY: Record<Exclude<Tab, '' | 'proses'>, { title: string; description: string }> = {
  outgoing: { title: 'Belum ada transaksi keluar', description: 'Aset yang dikirim ke pihak luar, sementara atau permanen, muncul di sini.' },
  incoming: { title: 'Belum ada transaksi masuk', description: 'Aset yang kembali dari pihak luar muncul di sini.' },
  not_returned: { title: 'Tidak ada aset yang belum kembali', description: 'Semua aset yang keluar sementara sudah dicatat masuk kembali.' },
}

function TypeBadge({ trx }: { trx: AssetTransaction }) {
  if (trx.type === 'outgoing') return <Badge tone="amber" icon={ArrowUpRight}>Keluar</Badge>
  if (trx.type === 'incoming') return <Badge tone="green" icon={ArrowDownLeft}>Masuk</Badge>
  return <Badge tone={trx.type === 'registration' ? 'green' : 'blue'}>{trx.type_label}</Badge>
}
const DEFAULT_COLUMNS = ['number', 'asset', 'type', 'changes', 'by']

/** Asset Transactions module: every recorded activity, searchable and filterable. */
export function TransactionsPage() {
  const [params, setParams] = usePersistedSearchParams('/transactions')
  const [search, setSearch] = useState(params.get('search') ?? '')

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/transactions')

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  filters.per_page ??= String(DEFAULT_PER_PAGE)
  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft

  const visibleColumns = (params.get('columns') ?? DEFAULT_COLUMNS.join(',')).split(',').filter(Boolean)

  const toggleColumn = (key: string) => {
    const next = visibleColumns.includes(key)
      ? visibleColumns.filter((c) => c !== key)
      : [...visibleColumns, key]
    update({ columns: next.join(',') })
  }

  const resetColumns = () => {
    update({ columns: DEFAULT_COLUMNS.join(',') })
  }

  const query = useQuery({
    queryKey: ['/transactions', filters],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/transactions${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  // Counts ignore the tab itself, paging and sorting, so they stay put while switching tabs.
  const { page: _page, per_page: _perPage, sort: _sort, columns: _columns, type: _type, not_returned: _notReturned, ...countFilters } = filters
  const counts = useQuery({
    queryKey: ['/transactions', 'counts', countFilters],
    queryFn: () => api.get<{ data: Record<string, number> }>(`/transactions/counts${qs(countFilters)}`),
    placeholderData: keepPreviousData,
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
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }

  // Every applied filter as a removable chip.
  const type = params.get('type') as TransactionType | null
  const nature = params.get('outgoing_nature') as OutgoingNature | null
  const inProcess = params.get('proses') === '1'
  const tab: Tab | 'other' = inProcess ? 'proses' : params.get('not_returned') === '1' ? 'not_returned' : !type ? '' : type === 'outgoing' || type === 'incoming' ? type : 'other'
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...(type ? [{ key: 'type', label: `Jenis: ${transactionTypeLabel[type] ?? type}`, clear: () => update({ type: '' }) }] : []),
    ...(nature ? [{ key: 'nature', label: `Sifat: ${outgoingNatureLabel[nature] ?? nature}`, clear: () => update({ outgoing_nature: '' }) }] : []),
    ...(params.get('date_from') ? [{ key: 'from', label: `Dari ${formatDate(params.get('date_from')!)}`, clear: () => update({ date_from: '' }) }] : []),
    ...(params.get('date_to') ? [{ key: 'to', label: `Sampai ${formatDate(params.get('date_to')!)}`, clear: () => update({ date_to: '' }) }] : []),
  ]

  // Transactions with a flow still on their way, for the "Sedang diproses" tab.
  const inProcessCount = useQuery({
    queryKey: ['/transaction-runs', 'in-process-count'],
    queryFn: async () => (await runsApi.list({ state: 'running', per_page: '1' })).meta.total,
  })

  const resetAll = () => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))

  const columns: Column<AssetTransaction>[] = [
    {
      key: 'number',
      header: 'Transaksi',
      sortKey: 'number',
      className: 'w-48',
      cell: (t) => (
        <div>
          <p className="font-mono text-xs text-slate-900">{t.transaction_number}</p>
          <p className="text-xs text-slate-500">{formatDate(t.transaction_date)}</p>
        </div>
      ),
    },
    {
      key: 'asset',
      header: 'Aset',
      sortKey: 'asset',
      className: 'w-[28%]',
      cell: (t) =>
        t.asset && (
          <Link to={`/aset/${t.asset.id}`} className="hover:text-brand-700">
            <span className="font-medium text-slate-900">{t.asset.name}</span>
          </Link>
        ),
    },
    { key: 'type', header: 'Jenis', className: 'w-36', sortKey: 'type', cell: (t) => <TypeBadge trx={t} /> },
    { key: 'changes', header: 'Rincian', className: 'whitespace-normal', cell: (t) => <TransactionChanges trx={t} />, hideOnMobile: true },
    { key: 'by', header: 'Dicatat oleh', className: 'w-40', sortKey: 'by', cell: (t) => t.created_by ?? '—', hideOnMobile: true },
  ]

  return (
    <>
      <PageHeader
        title="Riwayat Transaksi"
        description="Riwayat tiap aset: keluar, masuk, perubahan status, dan penambahan. Transaksi baru dibuat di menu Transaksi."
      />
      <Card>
        <div className="border-b border-slate-100 px-3 pt-3">
          <PillTabs
            label="Saring jenis transaksi"
            value={tab}
            onChange={(next) =>
              next === 'proses'
                ? setParams(new URLSearchParams({ proses: '1' }))
                : update({ proses: '', type: next === 'outgoing' || next === 'incoming' ? next : '', not_returned: next === 'not_returned' ? '1' : '' })
            }
            tabs={[
              ...TABS.map((t) => ({ value: t.value as Tab, label: t.label, count: counts.data?.data[t.count], attention: t.value === 'not_returned' })),
              { value: 'proses', label: 'Sedang diproses', count: inProcessCount.data },
            ]}
          />
        </div>
        {inProcess ? (
          <RunList
            bare
            params={{ state: 'running' }}
            empty="Tidak ada transaksi yang sedang diproses dalam cakupan data Anda."
            line={(r) => `Tahapan sekarang: ${r.current_step?.label ?? '-'}${r.open_task?.assignee_name ? ` · menunggu ${r.open_task.assignee_name}` : ''}`}
          />
        ) : (
        <>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nomor, aset, peminjam, pihak luar, nomor dokumen"
            label="Cari transaksi"
            aside={
              <ColumnToggle
                columns={columns}
                visibleColumns={visibleColumns}
                onToggle={toggleColumn}
                onReset={resetColumns}
              />
            }
          />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update(draft)} onClear={() => advanced.set(advanced.empty)}>
            <Select value={draft.type} onChange={(e) => advanced.set({ type: e.target.value })} aria-label="Jenis transaksi">
              <option value="">Jenis: semua</option>
              {(Object.keys(transactionTypeLabel) as TransactionType[]).map((t) => (
                <option key={t} value={t}>
                  {transactionTypeLabel[t]}
                </option>
              ))}
            </Select>
            <Select value={draft.outgoing_nature} onChange={(e) => advanced.set({ outgoing_nature: e.target.value })} aria-label="Sifat transaksi keluar">
              <option value="">Sifat keluar: semua</option>
              {(Object.keys(outgoingNatureLabel) as OutgoingNature[]).map((n) => (
                <option key={n} value={n}>
                  {outgoingNatureLabel[n]}
                </option>
              ))}
            </Select>
            <DateRangeFilter from={draft.date_from} to={draft.date_to} onChange={(from, to) => advanced.set({ date_from: from, date_to: to })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={5} label="Memuat transaksi…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={chips.length > 0 ? SearchX : ArrowLeftRight}
            title={chips.length > 0 ? 'Tidak ada transaksi yang cocok' : tab && tab !== 'other' && tab !== 'proses' ? TAB_EMPTY[tab].title : 'Belum ada transaksi'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : tab && tab !== 'other' && tab !== 'proses' ? TAB_EMPTY[tab].description : 'Transaksi muncul di sini setelah aset ditambahkan atau dicatat aktivitasnya.'}
            action={
              chips.length > 0 ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={query.data!.data}
              visibleColumns={visibleColumns}
              className="min-w-[36rem] md:min-w-[60rem]"
              sort={params.get('sort') ?? ''}
              onSort={(next) => update({ sort: next })}
            />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} onPerPage={(n) => update({ per_page: n === DEFAULT_PER_PAGE ? '' : String(n) })} />
          </>
        )}
        </>
        )}
      </Card>
    </>
  )
}
