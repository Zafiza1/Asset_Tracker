import clsx from 'clsx'
import { Boxes, ChevronRight, LogOut, Menu, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { OrganizationSwitcher } from '../features/organizations/OrganizationSwitcher'
import { useAuth } from '../lib/auth/context'
import { ModuleBoundary } from './ModuleBoundary'
import { breadcrumbLabels, breadcrumbTextOnly, platformNavigation, tenantNavigation, type NavSection } from './navigation'

export function AppLayout() {
  const { profile, can, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const { pathname } = useLocation()

  // The mobile drawer behaves like a dialog: Esc closes it and the page behind does not scroll.
  useEffect(() => {
    if (!mobileOpen) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMobileOpen(false)
    document.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
    }
  }, [mobileOpen])

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
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:shadow-raised">
        Lewati ke konten
      </a>

      {mobileOpen && <div className="fixed inset-0 z-30 animate-fade-in bg-slate-900/50 backdrop-blur-[2px] lg:hidden" onClick={() => setMobileOpen(false)} aria-hidden />}
      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-gradient-to-b from-brand-900 to-brand-950 text-brand-100 transition-transform duration-200 ease-out lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0',
          mobileOpen ? 'translate-x-0 shadow-overlay' : '-translate-x-full',
        )}
        aria-label="Navigasi utama"
      >
        <div className="flex h-16 shrink-0 items-center justify-between gap-2 px-5">
          <Link to="/" className="flex items-center gap-2.5 rounded-lg font-display font-semibold tracking-tight text-white">
            <span className="grid size-8 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15">
              <Boxes className="size-5" aria-hidden />
            </span>
            <span>Asset Tracker</span>
          </Link>
          <button type="button" className="grid size-9 place-items-center rounded-lg hover:bg-white/10 lg:hidden" onClick={() => setMobileOpen(false)} aria-label="Tutup menu">
            <X className="size-5" aria-hidden />
          </button>
        </div>
        <SideNav sections={sections} onNavigate={() => setMobileOpen(false)} />
        <div className="shrink-0 border-t border-white/10 p-3">
          <div className="flex items-center gap-3 rounded-lg px-2 py-2">
            <Avatar name={profile.user.name} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-white">{profile.user.name}</p>
              <p className="truncate text-xs text-brand-300">{profile.user.email}</p>
            </div>
          </div>
          {/* Logout is kept apart from the menu items so it is not hit by accident. */}
          <button type="button" onClick={handleLogout} className="mt-1 flex w-full items-center gap-3 rounded-lg px-2 py-2 text-sm text-brand-200 transition-colors hover:bg-white/10 hover:text-white">
            <LogOut className="size-4" aria-hidden />
            Keluar
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/85 px-4 backdrop-blur-md sm:px-6">
          <button type="button" className="-ml-1 grid size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Buka menu" aria-expanded={mobileOpen}>
            <Menu className="size-5" aria-hidden />
          </button>
          <div className="min-w-0 flex-1">
            {isPlatform ? <p className="truncate font-display text-sm font-semibold text-slate-900">Administrasi Platform</p> : <OrganizationSwitcher />}
          </div>
          <div className="flex items-center gap-2.5 lg:hidden">
            <Avatar name={profile.user.name} light />
          </div>
        </header>

        <main id="main" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <Breadcrumb />
          {/* Keyed by path: an error in one page never follows the user to another menu. */}
          <ModuleBoundary key={pathname}>
            <Outlet />
          </ModuleBoundary>
        </main>
      </div>
    </div>
  )
}

function SideNav({ sections, onNavigate }: { sections: NavSection[]; onNavigate: () => void }) {
  return (
    <nav className="flex-1 space-y-6 overflow-y-auto px-3 pb-6 pt-2">
      {sections.map((section, i) => (
        <div key={section.title ?? i}>
          {section.title && <p className="mb-1.5 px-2.5 text-[0.6875rem] font-semibold uppercase tracking-wider text-brand-300/90">{section.title}</p>}
          <ul className="space-y-0.5">
            {section.items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    clsx(
                      'group relative flex min-h-10 items-center gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors duration-150',
                      isActive ? 'bg-white/12 font-semibold text-white' : 'text-brand-100/90 hover:bg-white/8 hover:text-white',
                    )
                  }
                >
                  {({ isActive }) => (
                    <>
                      {/* Active marker: shape as well as color, so the current page is not shown by color alone. */}
                      <span className={clsx('absolute inset-y-2 left-0 w-1 rounded-r-full bg-brand-300 transition-opacity', isActive ? 'opacity-100' : 'opacity-0')} aria-hidden />
                      <item.icon className={clsx('size-4 shrink-0', isActive ? 'text-white' : 'text-brand-300 group-hover:text-white')} aria-hidden />
                      {item.label}
                    </>
                  )}
                </NavLink>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </nav>
  )
}

function Avatar({ name, light }: { name: string; light?: boolean }) {
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join('')
  return (
    <span className={clsx('grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold', light ? 'bg-brand-100 text-brand-800' : 'bg-white/15 text-white ring-1 ring-white/20')} aria-hidden>
      {initials || '?'}
    </span>
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
              ) : breadcrumbTextOnly.has(to) ? (
                <span>{label}</span>
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
