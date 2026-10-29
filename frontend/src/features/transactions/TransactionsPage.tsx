import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { formatDate } from '../../lib/format'
import { transactionTypeLabel, type AssetTransaction, type TransactionType } from './api'
import { TransactionChanges } from './TransactionChanges'
import { TransactionFormModal } from './TransactionFormModal'

const FILTERS = ['page', 'search', 'type', 'date_from', 'date_to', 'columns'] as const
const ADVANCED = ['type', 'date_from', 'date_to'] as const
const DEFAULT_COLUMNS = ['number', 'asset', 'type', 'changes', 'by']

/** Asset Transactions module: every recorded activity, searchable and filterable. */
export function TransactionsPage() {
  const { can } = useAuth()
  const [params, setParams] = usePersistedSearchParams('/transactions')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/transactions')

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
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
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...(type ? [{ key: 'type', label: `Jenis: ${transactionTypeLabel[type] ?? type}`, clear: () => update({ type: '' }) }] : []),
    ...(params.get('date_from') ? [{ key: 'from', label: `Dari ${formatDate(params.get('date_from')!)}`, clear: () => update({ date_from: '' }) }] : []),
    ...(params.get('date_to') ? [{ key: 'to', label: `Sampai ${formatDate(params.get('date_to')!)}`, clear: () => update({ date_to: '' }) }] : []),
  ]

  const columns: Column<AssetTransaction>[] = [
    {
      key: 'number',
      header: 'Transaksi',
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
      cell: (t) =>
        t.asset && (
          <Link to={`/aset/${t.asset.id}`} className="hover:text-brand-700">
            <span className="font-medium text-slate-900">{t.asset.name}</span>
            <span className="block font-mono text-xs text-slate-500">{t.asset.asset_number}</span>
          </Link>
        ),
    },
    { key: 'type', header: 'Jenis', cell: (t) => <Badge tone={t.type === 'registration' ? 'green' : 'blue'}>{t.type_label}</Badge> },
    { key: 'changes', header: 'Rincian', cell: (t) => <TransactionChanges trx={t} />, hideOnMobile: true },
    { key: 'by', header: 'Dicatat oleh', cell: (t) => t.created_by ?? '—', hideOnMobile: true },
  ]

  return (
    <>
      <PageHeader
        title="Transaksi Aset"
        actions={
          can('transaction.create') && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden /> Catat transaksi
            </Button>
          )
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nomor transaksi, aset, peminjam, catatan"
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
            <DateRangeFilter from={draft.date_from} to={draft.date_to} onChange={(from, to) => advanced.set({ date_from: from, date_to: to })} />
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={() => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            title={chips.length > 0 ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : 'Transaksi muncul di sini setelah aset ditambahkan atau dicatat aktivitasnya.'}
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} visibleColumns={visibleColumns} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      {creating && <TransactionFormModal onClose={() => setCreating(false)} />}
    </>
  )
}
