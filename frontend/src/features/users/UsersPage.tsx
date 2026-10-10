import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated, User } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDateTime, userStatusLabel } from '../../lib/format'

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
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const page = Number(params.get('page') ?? 1)
  const status = params.get('status') ?? ''

  const query = useQuery({
    queryKey: ['users', { page, status, search: params.get('search') }],
    queryFn: () => api.get<Paginated<User>>(`/users${qs({ page, status, search: params.get('search') })}`),
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

  return (
    <>
      <PageHeader
        title="Pengguna"
        description="Akun pengguna organisasi beserta role dan cakupan datanya."
        actions={
          can('user.create') &&
          can('role.manage') && (
            <Link to="/pengguna/baru">
              <Button>
                <Plus className="size-4" aria-hidden /> Tambah pengguna
              </Button>
            </Link>
          )
        }
      />
      <Card>
        <form
          className="flex flex-wrap gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            update({ search, page: '' })
          }}
          role="search"
        >
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" aria-hidden />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nama, email, atau NIK" className="pl-8" aria-label="Cari pengguna" />
          </div>
          <Select value={status} onChange={(e) => update({ status: e.target.value, page: '' })} className="w-auto" aria-label="Filter status">
            <option value="">Semua status</option>
            {Object.entries(userStatusLabel).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Cari
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Tidak ada pengguna" description="Ubah kata kunci atau filter pencarian." />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(u) => navigate(`/pengguna/${u.id}`)} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
    </>
  )
}
