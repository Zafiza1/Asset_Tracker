import { ArrowLeftRight, Boxes, Building2, FileClock, Users } from 'lucide-react'
import { Link } from 'react-router'
import { Card, PageHeader } from '../../components/ui/Card'
import { useAuth } from '../../lib/auth/context'
import { formatDateTime, scopeTypeLabel } from '../../lib/format'

const shortcuts = [
  { to: '/aset', label: 'Pendataan aset', icon: Boxes, permission: 'asset.view' },
  { to: '/transaksi', label: 'Transaksi aset', icon: ArrowLeftRight, permission: 'transaction.view' },
  { to: '/organisasi', label: 'Profil organisasi', icon: Building2, permission: 'organization.view' },
  { to: '/pengguna', label: 'Kelola pengguna', icon: Users, permission: 'user.view' },
  { to: '/audit', label: 'Audit trail', icon: FileClock, permission: 'audit.view' },
]

/**
 * Landing page for users without dashboard access (report.view): account information and
 * shortcuts only — no placeholder statistics.
 */
export function HomePage() {
  const { profile, can } = useAuth()
  if (!profile) return null
  const scope = profile.data_scope
  const available = shortcuts.filter((s) => can(s.permission))

  return (
    <>
      <PageHeader title={`Selamat datang, ${profile.user.name}`} description={profile.organization?.name} />
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Akun Anda" className="lg:col-span-1">
          <dl className="space-y-3 p-4 text-sm">
            <div>
              <dt className="text-slate-500">Jabatan</dt>
              <dd className="text-slate-800">{profile.user.job_title ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-slate-500">Cakupan data</dt>
              <dd className="text-slate-800">
                {scope?.organization_wide
                  ? scopeTypeLabel.organization
                  : [
                      scope?.location_ids.length ? `${scope.location_ids.length} lokasi` : null,
                    ]
                      .filter(Boolean)
                      .join(', ') || 'Belum ditetapkan'}
              </dd>
            </div>
            <div>
              <dt className="text-slate-500">Login terakhir</dt>
              <dd className="text-slate-800">{formatDateTime(profile.user.last_login_at)}</dd>
            </div>
          </dl>
        </Card>
        <Card title="Akses cepat" className="lg:col-span-2">
          {available.length === 0 ? (
            <p className="p-4 text-sm text-slate-500">Belum ada modul yang dapat Anda akses. Hubungi Admin organisasi.</p>
          ) : (
            <ul className="grid gap-3 p-4 sm:grid-cols-2">
              {available.map((s) => (
                <li key={s.to}>
                  <Link to={s.to} className="flex items-center gap-3 rounded-md p-3 ring-1 ring-slate-200 hover:bg-brand-50 hover:ring-brand-200">
                    <s.icon className="size-5 text-brand-700" aria-hidden />
                    <span className="text-sm font-medium text-slate-800">{s.label}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  )
}
