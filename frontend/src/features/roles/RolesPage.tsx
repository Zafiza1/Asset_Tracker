import { Lock, Plus, ShieldCheck } from 'lucide-react'
import { Navigate, useNavigate, useParams } from 'react-router'
import { ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { DataTable, type Column } from '../../components/ui/Table'
import type { Role } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { useRoles } from '../users/api'
import { UserAdminTabs } from '../users/UserAdminTabs'

const columns: Column<Role>[] = [
  {
    key: 'name',
    header: 'Role',
    sortValue: (r) => r.name,
    cell: (r) => (
      <div>
        <p className="flex items-center gap-1.5 font-medium text-slate-900">
          {r.name}
          {r.is_locked && <Lock className="size-3.5 text-slate-400" aria-label="Terkunci" />}
        </p>
        <p className="text-xs text-slate-500">{r.code}</p>
      </div>
    ),
  },
  { key: 'description', header: 'Deskripsi', sortValue: (r) => r.description, cell: (r) => r.description ?? '—', hideOnMobile: true },
  { key: 'permissions', header: 'Permission', sortValue: (r) => r.permissions?.length ?? 0, cell: (r) => r.permissions?.length ?? 0 },
  { key: 'users', header: 'Pengguna', sortValue: (r) => r.users_count ?? 0, cell: (r) => r.users_count ?? 0 },
]

export function RolesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const roles = useRoles()

  return (
    <>
      <PageHeader
        title="Pengguna"
        actions={
          can('role.manage') && (
            <ButtonLink to="/pengguna/role/baru">
                <Plus className="size-4" aria-hidden /> Tambah role
              </ButtonLink>
          )
        }
      />
      <UserAdminTabs />
      <Card>
        {roles.isLoading ? (
          <TableSkeleton rows={5} columns={4} label="Memuat role…" />
        ) : roles.isError ? (
          <ErrorState error={roles.error} onRetry={() => void roles.refetch()} />
        ) : roles.data!.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title="Belum ada role"
            description="Role menentukan apa yang boleh dilihat dan dilakukan pengguna."
            action={
              can('role.manage') && (
                <ButtonLink to="/pengguna/role/baru">
                    <Plus className="size-4" aria-hidden /> Tambah role
                  </ButtonLink>
              )
            }
          />
        ) : (
          <DataTable columns={columns} rows={roles.data!} onRowClick={(r) => navigate(`/pengguna/role/${r.id}`)} />
        )}
      </Card>
    </>
  )
}

/** Old /role/:id links (before roles moved under Pengguna). */
export function LegacyRoleRedirect() {
  const { id } = useParams()
  return <Navigate to={`/pengguna/role/${id}`} replace />
}
