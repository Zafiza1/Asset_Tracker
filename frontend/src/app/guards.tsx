import { AlertTriangle, ShieldAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { isRouteErrorResponse, Navigate, Outlet, useLocation, useRouteError } from 'react-router'
import { Spinner } from '../components/ui/Feedback'
import { ApiError } from '../lib/api/client'
import { useAuth } from '../lib/auth/context'

/** Pages a tenant user can open before an organization is active. */
const ORGANIZATION_FREE_PATHS = new Set(['/ubah-password', '/pilih-organisasi', '/organisasi/baru'])

/**
 * Requires a session; users with a temporary password are sent to change it first, and
 * tenant users without an active organization (several to choose from) pick one first.
 */
export function RequireAuth() {
  const { profile, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <Spinner />
  if (!profile) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (profile.user.must_change_password && location.pathname !== '/ubah-password') {
    return <Navigate to="/ubah-password" replace />
  }
  if (profile.user.user_type === 'tenant' && !profile.organization && !ORGANIZATION_FREE_PATHS.has(location.pathname)) {
    return <Navigate to="/pilih-organisasi" replace />
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

/** Route errorElement: an exception thrown while rendering a page, shown instead of a blank screen. */
export function RouteError() {
  const error = useRouteError()
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFound />
  console.error(error)
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <AlertTriangle className="size-10 text-slate-400" aria-hidden />
      <h1 className="text-lg font-semibold text-slate-800">Terjadi kesalahan</h1>
      <p className="text-sm text-slate-500">Halaman ini gagal ditampilkan. Muat ulang halaman, atau hubungi admin jika masalah berlanjut.</p>
      {error instanceof ApiError && error.requestId && <p className="text-xs text-slate-400">ID permintaan: {error.requestId}</p>}
      <button type="button" onClick={() => window.location.reload()} className="mt-2 text-sm font-medium text-brand-600 hover:underline">
        Muat ulang
      </button>
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
