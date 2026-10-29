import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated, User } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { formatDateTime, userStatusLabel } from '../../lib/format'
import { UserAdminTabs } from './UserAdminTabs'

const DEFAULT_COLUMNS = ['name', 'roles', 'job', 'status', 'login']
const columns: Column<User>[] = [
  {
    key: 'name',
    header: 'Nama',
    cell: (u) => (
      <div>
        <p className="font-medium text-slate-900">{u.name}</p>
        <p className="text-xs text-slate-500">{u.email}</p>
      </div>
    ),
  },
  { key: 'roles', header: 'Role', cell: (u) => u.roles?.map((r) => r.name).join(', ') || '—', hideOnMobile: true },
  { key: 'job', header: 'Jabatan', cell: (u) => u.job_title ?? '—', hideOnMobile: true },
  { key: 'status', header: 'Status', cell: (u) => <Badge tone={statusTone[u.status]}>{userStatusLabel[u.status]}</Badge> },
  { key: 'login', header: 'Login terakhir', cell: (u) => formatDateTime(u.last_login_at), hideOnMobile: true },
]

export function UsersPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = usePersistedSearchParams('/users')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const page = Number(params.get('page') ?? 1)
  const status = params.get('status') ?? ''

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/users')

  const advanced = useFilterDraft(params, ['status'])

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
    queryKey: ['users', { page, status, search: params.get('search') }],
    queryFn: () => api.get<Paginated<User>>(`/users${qs({ page, status, search: params.get('search') })}`),
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
    setParams(merged)
  }
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '', page: '' }))),
    ...(status ? [{ key: 'status', label: `Status: ${userStatusLabel[status as keyof typeof userStatusLabel] ?? status}`, clear: () => update({ status: '', page: '' }) }] : []),
  ]

  return (
    <>
      <PageHeader
        title="Pengguna"
        actions={
          can('user.create') &&
          can('role.manage') && (
            <Link to="/pengguna/baru">
              <Button>
                <Plus className="size-4" aria-hidden /> Undang pengguna
              </Button>
            </Link>
          )
        }
      />
      <UserAdminTabs />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search, page: '' })}
            placeholder="Cari nama, email, atau NIK"
            label="Cari pengguna"
            aside={
              <ColumnToggle
                columns={columns}
                visibleColumns={visibleColumns}
                onToggle={toggleColumn}
                onReset={resetColumns}
              />
            }
          />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update({ ...advanced.draft, page: '' })} onClear={() => advanced.set(advanced.empty)}>
            <Select value={advanced.draft.status} onChange={(e) => advanced.set({ status: e.target.value })} aria-label="Filter status">
              <option value="">Status: semua</option>
              {Object.entries(userStatusLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={() => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title={chips.length > 0 ? 'Tidak ada pengguna yang cocok' : 'Belum ada pengguna'} description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : undefined} />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} visibleColumns={visibleColumns} onRowClick={(u) => navigate(`/pengguna/${u.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
    </>
  )
}
