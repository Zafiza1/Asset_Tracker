import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { transactionTypeLabel, type AssetTransaction, type TransactionType } from './api'
import { TransactionChanges } from './TransactionChanges'
import { TransactionFormModal } from './TransactionFormModal'

const FILTERS = ['page', 'search', 'type', 'date_from', 'date_to'] as const

/** Asset Transactions module: every recorded activity, searchable and filterable. */
export function TransactionsPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))

  const query = useQuery({
    queryKey: ['/transactions', filters],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/transactions${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }

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
        description="Riwayat seluruh aktivitas aset: penambahan, pemindahan, perubahan status, peminjaman, dan pengembalian. Transaksi tidak dapat diubah; kesalahan diperbaiki dengan transaksi baru."
        actions={
          can('transaction.create') && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden /> Catat transaksi
            </Button>
          )
        }
      />
      <Card>
        <form
          role="search"
          className="flex flex-wrap gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            update({ search })
          }}
        >
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nomor transaksi, aset, peminjam, catatan" className="pl-8" aria-label="Cari transaksi" />
          </div>
          <Select value={params.get('type') ?? ''} onChange={(e) => update({ type: e.target.value })} className="w-auto" aria-label="Jenis transaksi">
            <option value="">Semua jenis</option>
            {(Object.keys(transactionTypeLabel) as TransactionType[]).map((t) => (
              <option key={t} value={t}>
                {transactionTypeLabel[t]}
              </option>
            ))}
          </Select>
          <Input type="date" value={params.get('date_from') ?? ''} onChange={(e) => update({ date_from: e.target.value })} className="w-auto" aria-label="Dari tanggal" />
          <Input type="date" value={params.get('date_to') ?? ''} onChange={(e) => update({ date_to: e.target.value })} className="w-auto" aria-label="Sampai tanggal" />
          <Button type="submit" variant="secondary">
            Cari
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Belum ada transaksi" description="Transaksi muncul di sini setelah aset ditambahkan atau dicatat aktivitasnya." />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      {creating && <TransactionFormModal onClose={() => setCreating(false)} />}
    </>
  )
}
