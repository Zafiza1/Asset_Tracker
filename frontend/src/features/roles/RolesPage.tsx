import { Lock, Plus } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { DataTable, type Column } from '../../components/ui/Table'
import type { Role } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { useRoles } from '../users/api'

const columns: Column<Role>[] = [
  {
    key: 'name',
    header: 'Role',
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
  { key: 'description', header: 'Deskripsi', cell: (r) => r.description ?? '—', hideOnMobile: true },
  { key: 'permissions', header: 'Permission', cell: (r) => r.permissions?.length ?? 0 },
  { key: 'users', header: 'Pengguna', cell: (r) => r.users_count ?? 0 },
]

export function RolesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const roles = useRoles()

  return (
    <>
      <PageHeader
        title="Role & Permission"
        description="Role adalah kumpulan permission. Akses akhir pengguna juga dibatasi oleh cakupan data."
        actions={
          can('role.manage') && (
            <Link to="/role/baru">
              <Button>
                <Plus className="size-4" aria-hidden /> Tambah role
              </Button>
            </Link>
          )
        }
      />
      <Card>
        {roles.isLoading ? (
          <Spinner />
        ) : roles.isError ? (
          <ErrorState error={roles.error} />
        ) : roles.data!.length === 0 ? (
          <EmptyState title="Belum ada role" />
        ) : (
          <DataTable columns={columns} rows={roles.data!} onRowClick={(r) => navigate(`/role/${r.id}`)} />
        )}
      </Card>
    </>
  )
}
