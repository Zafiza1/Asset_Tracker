import clsx from 'clsx'
import { Boxes, ChevronRight, LogOut, Menu, X } from 'lucide-react'
import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../lib/auth/context'
import { breadcrumbLabels, platformNavigation, tenantNavigation, type NavSection } from './navigation'

export function AppLayout() {
  const { profile, can, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  if (!profile) return null

  const isPlatform = profile.user.user_type === 'platform'
  const sections = (isPlatform ? platformNavigation : tenantNavigation)
    .map((s) => ({ ...s, items: s.items.filter((i) => !i.permission || can(i.permission)) }))
    .filter((s) => s.items.length > 0)

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-dvh lg:flex">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">
        Lewati ke konten
      </a>

      {mobileOpen && <div className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" onClick={() => setMobileOpen(false)} aria-hidden />}
      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-brand-900 text-brand-100 transition-transform lg:static lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-label="Navigasi utama"
      >
        <div className="flex h-16 items-center justify-between gap-2 px-5">
          <Link to="/" className="flex items-center gap-2 font-semibold text-white">
            <Boxes className="size-6" aria-hidden />
            <span>Asset Tracker</span>
          </Link>
          <button type="button" className="rounded p-1 hover:bg-brand-800 lg:hidden" onClick={() => setMobileOpen(false)} aria-label="Tutup menu">
            <X className="size-5" aria-hidden />
          </button>
        </div>
        <SideNav sections={sections} onNavigate={() => setMobileOpen(false)} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-6">
          <button type="button" className="rounded p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Buka menu">
            <Menu className="size-5" aria-hidden />
          </button>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-slate-900">{isPlatform ? 'Administrasi Platform' : profile.organization?.name}</p>
            {!isPlatform && <p className="truncate text-xs text-slate-500">{profile.organization?.code}</p>}
          </div>
          <div className="hidden text-right sm:block">
            <p className="text-sm font-medium text-slate-800">{profile.user.name}</p>
            <p className="text-xs text-slate-500">{profile.user.email}</p>
          </div>
          <button type="button" onClick={handleLogout} className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">
            <LogOut className="size-4" aria-hidden />
            <span className="hidden sm:inline">Keluar</span>
          </button>
        </header>

        <main id="main" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">
          <Breadcrumb />
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function SideNav({ sections, onNavigate }: { sections: NavSection[]; onNavigate: () => void }) {
  return (
    <nav className="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
      {sections.map((section, i) => (
        <div key={section.title ?? i}>
          {section.title && <p className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-brand-300">{section.title}</p>}
          <ul className="space-y-0.5">
            {section.items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    clsx('flex items-center gap-3 rounded-md px-2 py-2 text-sm', isActive ? 'bg-brand-700 text-white' : 'text-brand-100 hover:bg-brand-800 hover:text-white')
                  }
                >
                  <item.icon className="size-4" aria-hidden />
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </nav>
  )
}

function Breadcrumb() {
  const { pathname } = useLocation()
  const segments = pathname.split('/').filter(Boolean)
  if (segments.length === 0) return null

  return (
    <nav aria-label="Breadcrumb" className="mb-4">
      <ol className="flex flex-wrap items-center gap-1 text-sm text-slate-500">
        <li>
          <Link to="/" className="hover:text-brand-700">
            Beranda
          </Link>
        </li>
        {segments.map((segment, i) => {
          const to = '/' + segments.slice(0, i + 1).join('/')
          const label = breadcrumbLabels[segment] ?? 'Detail'
          const last = i === segments.length - 1
          return (
            <li key={to} className="flex items-center gap-1">
              <ChevronRight className="size-3.5" aria-hidden />
              {last ? (
                <span aria-current="page" className="text-slate-700">
                  {label}
                </span>
              ) : (
                <Link to={to} className="hover:text-brand-700">
                  {label}
                </Link>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
