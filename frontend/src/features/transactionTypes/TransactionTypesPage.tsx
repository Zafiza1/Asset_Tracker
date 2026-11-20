import { useQuery } from '@tanstack/react-query'
import { Lock, Plus, Workflow } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { FilterPanel, SearchForm } from '../../components/ui/Filters'
import { DataTable, type Column } from '../../components/ui/Table'
import { useAuth } from '../../lib/auth/context'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { typeStatusLabel, typeStatusTone, typesApi, type TransactionTypeDefinition, type TypeStatus } from './api'

/** '' every type, the organization's own, or the seven system types (F-07f). */
type Kind = '' | 'organization' | 'system'

/**
 * Jenis Transaksi (F-07 §3): the organization's own transaction types with their fields and
 * flows. Creating and changing them is for Super Admin and Supervisor on wide screens (D-27,
 * D-34); everyone with transaction.view sees the list and the flows. The seven system types
 * (F-07f-1) are listed too, read-only, until their flows arrive (F-07f-2).
 */
export function TransactionTypesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const canUseEditor = useCanUseEditor()
  const canManage = can('transaction_type.manage') && canUseEditor
  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState('')
  const [status, setStatus] = useState<TypeStatus | ''>('')
  const [kind, setKind] = useState<Kind>('')

  const query = useQuery({
    queryKey: ['/transaction-types', 'with-system', applied, status],
    queryFn: () => typesApi.list({ search: applied || undefined, status: status || undefined, include_system: '1' }),
    placeholderData: (prev) => prev,
  })
  const all = query.data?.data ?? []
  // The organization's own types first; the seven system types after them.
  const rows = all
    .filter((t) => (kind === 'system' ? t.system_type : kind === 'organization' ? !t.system_type : true))
    .sort((a, b) => Number(!!a.system_type) - Number(!!b.system_type))
  const systemCount = all.filter((t) => t.system_type).length
  const filtered = applied !== '' || status !== ''

  const columns: Column<TransactionTypeDefinition>[] = [
    {
      key: 'name',
      header: 'Jenis',
      className: 'whitespace-normal',
      cell: (t) => (
        <div className="min-w-0">
          <span className="flex flex-wrap items-center gap-1.5">
            <Link to={`/transaksi/jenis/${t.id}`} className="font-medium text-slate-900 hover:text-brand-700 hover:underline">
              {t.name}
            </Link>
            {t.system_type && (
              <Badge tone="slate">
                <Lock className="size-3" aria-hidden />
                Sistem
              </Badge>
            )}
          </span>
          <span className="block truncate font-mono text-xs text-slate-500">
            {t.system_type ? (
              `${t.code} · aturan inti tetap`
            ) : (
              <>
                {t.code} · {t.fields?.length ?? 0} field
                {t.closes_type ? ` · menutup "${t.closes_type.name}"` : ''}
              </>
            )}
          </span>
        </div>
      ),
    },
    {
      key: 'categories',
      header: 'Kategori aset',
      className: 'w-48',
      hideOnMobile: true,
      cell: (t) => (t.categories?.length ? t.categories.map((c) => c.name).join(', ') : <span className="text-slate-500">Semua</span>),
      title: (t) => t.categories?.map((c) => c.name).join(', '),
    },
    {
      key: 'flow',
      header: 'Alur',
      className: 'w-44',
      cell: (t) => (
        <div className="flex flex-wrap gap-1">
          {t.flow?.active_version ? <Badge tone="green">Terbit v{t.flow.active_version}</Badge> : null}
          {t.flow?.draft_version ? <Badge tone="amber">Draft v{t.flow.draft_version}</Badge> : null}
          {!t.flow?.active_version && !t.flow?.draft_version && <span className="text-xs text-slate-500">Langsung tercatat</span>}
        </div>
      ),
    },
    { key: 'status', header: 'Status', className: 'w-28', cell: (t) => <Badge tone={typeStatusTone[t.status]}>{typeStatusLabel[t.status]}</Badge> },
    {
      key: 'used',
      header: 'Dipakai',
      className: 'w-28 text-right',
      hideOnMobile: true,
      // A system type's transactions are recorded as the built-in type, not counted against the row.
      cell: (t) =>
        t.system_type ? (
          <span className="text-xs text-slate-500">Menu Transaksi</span>
        ) : (
          <span className="tabular-nums">{t.transactions_count ? `${t.transactions_count} transaksi` : '—'}</span>
        ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-24 text-right',
      // Flows for system types arrive with F-07f-2; until then they record at once.
      cell: (t) => t.system_type ? null : (
        <ButtonLink to={`/transaksi/jenis/${t.id}/alur`} variant="secondary" size="sm" aria-label={`Alur ${t.name}`}>
          <Workflow className="size-4" aria-hidden />
          Alur
        </ButtonLink>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Jenis Transaksi"
        description="Jenis transaksi organisasi beserta field dan alurnya. Alur yang diterbitkan otomatis berlaku untuk setiap transaksi jenis itu."
        actions={
          canManage && (
            <ButtonLink to="/transaksi/jenis/baru">
              <Plus className="size-4" aria-hidden />
              Jenis baru
            </ButtonLink>
          )
        }
      />
      {!canUseEditor && can('transaction_type.manage') && (
        <div className="mb-4">
          <Alert tone="info" title="Tampilan saja di ponsel">
            Membuat dan mengubah jenis, field, dan alur dilakukan di web pada layar lebar. Di ponsel Anda dapat melihat alurnya dan mencatat transaksi.
          </Alert>
        </div>
      )}
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => setApplied(search.trim())}
            onClear={() => (setSearch(''), setApplied(''))}
            placeholder="Cari nama atau kode jenis"
            label="Cari jenis transaksi"
            aside={
              <>
                <Select value={kind} onChange={(e) => setKind(e.target.value as Kind)} aria-label="Filter jenis" className="sm:w-48">
                  <option value="">Semua jenis</option>
                  <option value="organization">Buatan organisasi</option>
                  <option value="system">Sistem</option>
                </Select>
                <Select value={status} onChange={(e) => setStatus(e.target.value as TypeStatus | '')} aria-label="Filter status" className="sm:w-40">
                  <option value="">Status: semua</option>
                  {(Object.keys(typeStatusLabel) as TypeStatus[]).map((s) => (
                    <option key={s} value={s}>
                      {typeStatusLabel[s]}
                    </option>
                  ))}
                </Select>
              </>
            }
          />
        </FilterPanel>
        {query.isPending ? (
          <TableSkeleton columns={5} rows={5} />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : rows.length === 0 ? (
          filtered || kind === 'system' ? (
            <EmptyState title="Jenis tidak ditemukan" description="Ubah kata kunci atau filter." />
          ) : (
            <EmptyState
              icon={Workflow}
              title="Belum ada jenis buatan organisasi"
              description={`${systemCount ? `${systemCount} jenis sistem sudah tersedia. ` : ''}Buat jenis sendiri, mis. Mutasi Cabang atau Kalibrasi, lengkap dengan field dan alurnya.`}
              action={
                canManage && (
                  <ButtonLink to="/transaksi/jenis/baru">
                    <Plus className="size-4" aria-hidden />
                    Jenis baru
                  </ButtonLink>
                )
              }
            />
          )
        ) : (
          <DataTable columns={columns} rows={rows} onRowClick={(t) => navigate(`/transaksi/jenis/${t.id}`)} rowHref={(t) => `/transaksi/jenis/${t.id}`} />
        )}
        {rows.length > 0 && (
          <p className="flex items-center gap-1.5 border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
            <Lock className="size-3.5" aria-hidden />
            Aturan inti tetap berlaku untuk semua jenis: aset yang dipinjam, di luar organisasi, atau nonaktif tidak dapat ditransaksikan sembarangan.
          </p>
        )}
      </Card>
    </>
  )
}
