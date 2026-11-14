import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus, SearchX, Users } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { StatusBadge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated, User } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { formatDateTime, userStatusLabel } from '../../lib/format'
import { useRoles } from './api'
import { UserAdminTabs } from './UserAdminTabs'

const DEFAULT_COLUMNS = ['name', 'roles', 'job', 'status', 'login']

const roleNames = (u: User) => u.roles?.map((r) => r.name).join(', ') || null

/** The same "12 Nov 2026, 14.30" format on every row, or a muted note for accounts that never signed in. */
function LastLogin({ value }: { value: string | null }) {
  if (!value) return <span className="text-slate-400">Belum pernah login</span>
  return (
    <time dateTime={value} className="tabular-nums">
      {formatDateTime(value)}
    </time>
  )
}

const columns: Column<User>[] = [
  {
    key: 'name',
    header: 'Nama',
    title: (u) => `${u.name} · ${u.email}`,
    cell: (u) => (
      <div className="min-w-0">
        <p className="truncate font-medium text-slate-900">{u.name}</p>
        <p className="truncate text-xs text-slate-500">{u.email}</p>
        {/* On phones the role and last-login columns are hidden, so their values sit under the name. */}
        <p className="mt-1 truncate text-xs text-slate-500 md:hidden">
          {roleNames(u) ?? 'Tanpa role'} · {u.last_login_at ? `Login ${formatDateTime(u.last_login_at)}` : 'Belum pernah login'}
        </p>
      </div>
    ),
  },
  { key: 'roles', header: 'Role', title: (u) => roleNames(u), cell: (u) => roleNames(u) ?? '—', hideOnMobile: true },
  { key: 'job', header: 'Jabatan', title: (u) => u.job_title, cell: (u) => u.job_title ?? '—', hideOnMobile: true },
  { key: 'status', header: 'Status', className: 'w-28', cell: (u) => <StatusBadge status={u.status}>{userStatusLabel[u.status]}</StatusBadge> },
  { key: 'login', header: 'Login terakhir', className: 'w-44', cell: (u) => <LastLogin value={u.last_login_at} />, hideOnMobile: true },
]

export function UsersPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = usePersistedSearchParams('/users')
  const [search, setSearch] = useState(params.get('search') ?? '')
  const page = Number(params.get('page') ?? 1)
  const status = params.get('status') ?? ''
  const roleId = params.get('role_id') ?? ''
  const canFilterRole = can('role.view')
  const roles = useRoles(canFilterRole)

  // Persisted filters from localStorage
  const { updateFilter: persistFilter, removeFilter: unPersistFilter, resetFilters } = usePersistedFilters('/users')

  const advanced = useFilterDraft(params, canFilterRole ? ['status', 'role_id'] : ['status'])

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
    queryKey: ['users', { page, status, roleId, search: params.get('search') }],
    queryFn: () => api.get<Paginated<User>>(`/users${qs({ page, status, role_id: roleId, search: params.get('search') })}`),
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
    ...(roleId ? [{ key: 'role_id', label: `Role: ${roles.data?.find((r) => r.id === roleId)?.name ?? '…'}`, clear: () => update({ role_id: '', page: '' }) }] : []),
  ]

  const resetAll = () => (setSearch(''), resetFilters(), setParams(new URLSearchParams()))

  const meta = query.data?.meta

  return (
    <>
      <PageHeader
        title="Pengguna"
        actions={
          can('user.create') &&
          can('role.manage') && (
            <ButtonLink to="/pengguna/baru">
                <Plus className="size-4" aria-hidden /> Undang pengguna
              </ButtonLink>
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
            onClear={() => params.get('search') && update({ search: '', page: '' })}
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
          <AdvancedFilters defaultOpen activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update({ ...advanced.draft, page: '' })} onClear={() => advanced.set(advanced.empty)}>
            <Select value={advanced.draft.status} onChange={(e) => advanced.set({ status: e.target.value })} aria-label="Filter status">
              <option value="">Status: semua</option>
              {Object.entries(userStatusLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
            {canFilterRole && (
              <Select value={advanced.draft.role_id} onChange={(e) => advanced.set({ role_id: e.target.value })} aria-label="Filter role" disabled={roles.isLoading}>
                <option value="">Role: semua</option>
                {roles.data?.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </Select>
            )}
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={5} label="Memuat pengguna…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={chips.length > 0 ? SearchX : Users}
            title={chips.length > 0 ? 'Tidak ada pengguna yang cocok' : 'Belum ada pengguna'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : 'Undang rekan kerja agar mereka dapat ikut mengelola aset organisasi ini.'}
            action={
              chips.length > 0 ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : (
                can('user.create') &&
                can('role.manage') && (
                  <ButtonLink to="/pengguna/baru">
                      <Plus className="size-4" aria-hidden /> Undang pengguna
                    </ButtonLink>
                )
              )
            }
          />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={query.data!.data}
              visibleColumns={[...visibleColumns, 'actions']}
              onRowClick={(u) => navigate(`/pengguna/${u.id}`)}
              rowHref={(u) => `/pengguna/${u.id}`}
            />
            {/* One page needs no paging controls; the count alone is enough. */}
            {meta && meta.last_page > 1 ? (
              <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
            ) : (
              meta && <p className="border-t border-slate-200 px-4 py-3 text-sm tabular-nums text-slate-500">{meta.total} pengguna</p>
            )}
          </>
        )}
      </Card>
    </>
  )
}
