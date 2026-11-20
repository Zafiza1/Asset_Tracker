import { AlertTriangle, FileQuestion, Home, RotateCw, ShieldAlert, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { isRouteErrorResponse, Link, Navigate, Outlet, useLocation, useRouteError } from 'react-router'
import { Button } from '../components/ui/Button'
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

/** Full-area message for a page that cannot be shown, always with a way out. */
function PageMessage({ icon: Icon, title, children, actions }: { icon: LucideIcon; title: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
      <span className="mb-2 grid size-16 place-items-center rounded-full bg-slate-100 text-slate-500" aria-hidden>
        <Icon className="size-8" />
      </span>
      <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
      <div className="max-w-md space-y-1 text-sm text-slate-600">{children}</div>
      <div className="mt-4 flex flex-wrap justify-center gap-2">
        {actions}
        <Link to="/" className={linkButton}>
          <Home className="size-4" aria-hidden /> Ke halaman utama
        </Link>
      </div>
    </div>
  )
}

const linkButton =
  'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-card ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 hover:text-slate-900'

export function Forbidden() {
  return (
    <PageMessage icon={ShieldAlert} title="Akses ditolak">
      <p>Anda tidak memiliki izin untuk membuka halaman ini.</p>
      <p>Bila Anda memerlukannya, minta Admin organisasi menambahkan izin pada role Anda.</p>
    </PageMessage>
  )
}

/** Route errorElement: an exception thrown while rendering a page, shown instead of a blank screen. */
export function RouteError() {
  const error = useRouteError()
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFound />
  console.error(error)
  return (
    <PageMessage
      icon={AlertTriangle}
      title="Terjadi kesalahan"
      actions={
        <Button onClick={() => window.location.reload()}>
          <RotateCw className="size-4" aria-hidden /> Muat ulang
        </Button>
      }
    >
      <p>Halaman ini gagal ditampilkan. Muat ulang halaman, atau hubungi admin jika masalah berlanjut.</p>
      {error instanceof ApiError && error.requestId && <p className="text-xs text-slate-400">ID permintaan: {error.requestId}</p>}
    </PageMessage>
  )
}

export function NotFound() {
  return (
    <PageMessage icon={FileQuestion} title="Halaman tidak ditemukan">
      <p>Alamat yang Anda buka tidak ada atau sudah dipindahkan. Periksa kembali alamatnya, atau cari lewat menu.</p>
    </PageMessage>
  )
}
