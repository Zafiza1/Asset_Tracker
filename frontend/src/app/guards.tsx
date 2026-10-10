import { ShieldAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import { Spinner } from '../components/ui/Feedback'
import { useAuth } from '../lib/auth/context'

/** Requires a session; users with a temporary password are sent to change it first. */
export function RequireAuth() {
  const { profile, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <Spinner />
  if (!profile) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (profile.user.must_change_password && location.pathname !== '/ubah-password') {
    return <Navigate to="/ubah-password" replace />
  }
  return <Outlet />
}

export function RequireUserType({ type, children }: { type: 'tenant' | 'platform'; children: ReactNode }) {
  const { profile } = useAuth()
  if (profile?.user.user_type !== type) {
    return <Navigate to={type === 'platform' ? '/' : '/platform/organisasi'} replace />
  }
  return children
}

/** Hides pages the user cannot use. Purely UX: the API returns 403 regardless. */
export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useAuth()
  if (!can(permission)) return <Forbidden />
  return children
}

export function Forbidden() {
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <ShieldAlert className="size-10 text-slate-400" aria-hidden />
      <h1 className="text-lg font-semibold text-slate-800">Akses ditolak</h1>
      <p className="text-sm text-slate-500">Anda tidak memiliki izin untuk membuka halaman ini.</p>
    </div>
  )
}

export function NotFound() {
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <h1 className="text-lg font-semibold text-slate-800">Halaman tidak ditemukan</h1>
      <p className="text-sm text-slate-500">Periksa kembali alamat yang Anda buka.</p>
    </div>
  )
}
