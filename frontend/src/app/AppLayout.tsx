import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Bell, Boxes, CheckCircle2, ChevronDown, ChevronRight, LogOut, Menu, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { APPROVALS_KEY, useApprovalSummary } from '../features/approvals/api'
import { onApprovalPending, type ApprovalPendingResponse } from '../lib/api/client'
import { OrganizationSwitcher } from '../features/organizations/OrganizationSwitcher'
import { useAuth } from '../lib/auth/context'
import { ModuleBoundary } from './ModuleBoundary'
import { breadcrumbLabels, breadcrumbTextOnly, platformNavigation, tenantNavigation, type NavSection } from './navigation'

export function AppLayout() {
  const { profile, can, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  // Desktop only: the sidebar can be put away to give the page the full width; remembered per browser.
  const [desktopCollapsed, setDesktopCollapsed] = useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'
    } catch {
      return false
    }
  })
  const { pathname } = useLocation()

  const toggleDesktop = (collapsed: boolean) => {
    setDesktopCollapsed(collapsed)
    try {
      localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0')
    } catch {
      // Storage unavailable: the choice lasts for this page only.
    }
  }

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
    .map((s) => ({ ...s, items: s.items.filter((i) => (!i.permission || can(i.permission)) && !(i.hiddenWithPermission && can(i.hiddenWithPermission))) }))
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
          desktopCollapsed && 'lg:hidden',
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
          <button type="button" className="hidden size-9 place-items-center rounded-lg text-brand-200 hover:bg-white/10 hover:text-white lg:grid" onClick={() => toggleDesktop(true)} aria-label="Tutup sidebar" title="Tutup sidebar">
            <PanelLeftClose className="size-5" aria-hidden />
          </button>
        </div>
        <SideNav sections={sections} onNavigate={() => setMobileOpen(false)} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/80 bg-white/85 px-4 backdrop-blur-md sm:px-6">
          <button type="button" className="-ml-1 grid size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Buka menu" aria-expanded={mobileOpen}>
            <Menu className="size-5" aria-hidden />
          </button>
          {desktopCollapsed && (
            <button type="button" className="-ml-1 hidden size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:grid" onClick={() => toggleDesktop(false)} aria-label="Buka sidebar" title="Buka sidebar">
              <PanelLeftOpen className="size-5" aria-hidden />
            </button>
          )}
          <div className="min-w-0 flex-1">
            {isPlatform ? <p className="truncate font-display text-sm font-semibold text-slate-900">Administrasi Platform</p> : <OrganizationSwitcher />}
          </div>
          {!isPlatform && <NotificationBell approver={can('approval.manage')} />}
          <UserMenu name={profile.user.name} email={profile.user.email} onLogout={handleLogout} />
        </header>

        <main id="main" className="w-full flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          {/* Capped width: on wide monitors lines and tables stay readable instead of stretching edge to edge. */}
          <div className="mx-auto w-full max-w-[100rem]">
            <Breadcrumb />
            {/* Keyed by path: an error in one page never follows the user to another menu. */}
            <ModuleBoundary key={pathname}>
              <Outlet />
            </ModuleBoundary>
          </div>
        </main>
      </div>
      {!isPlatform && <ApprovalToast />}
    </div>
  )
}

/**
 * Header shortcut to the approval page: "Persetujuan" with the number of pending requests for
 * approvers, "Pengajuan Saya" (the outcome of one's own submissions) for everyone else.
 */
function NotificationBell({ approver }: { approver: boolean }) {
  const pending = useApprovalSummary(approver).data?.data.pending ?? 0
  const label = approver ? 'Persetujuan' : 'Pengajuan Saya'
  return (
    <Link
      to="/notifikasi"
      className="relative grid size-10 shrink-0 place-items-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
      aria-label={pending > 0 ? `${label}, ${pending} pengajuan menunggu` : label}
      title={label}
    >
      <Bell className="size-5" aria-hidden />
      {pending > 0 && (
        <span className="absolute right-1 top-1 grid min-w-4.5 place-items-center rounded-full bg-red-600 px-1 text-[0.6875rem] font-semibold leading-4.5 text-white" aria-hidden>
          {pending > 99 ? '99+' : pending}
        </span>
      )}
    </Link>
  )
}

/**
 * Shown when a change was submitted for approval instead of being applied (API 202), wherever
 * it was made; also refreshes the notification badge.
 */
function ApprovalToast() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [submitted, setSubmitted] = useState<ApprovalPendingResponse | null>(null)

  useEffect(
    () =>
      onApprovalPending((response) => {
        setSubmitted(response)
        void queryClient.invalidateQueries({ queryKey: APPROVALS_KEY })
      }),
    [queryClient],
  )
  useEffect(() => {
    if (!submitted) return
    const timer = setTimeout(() => setSubmitted(null), 8000)
    return () => clearTimeout(timer)
  }, [submitted])

  if (!submitted) return null
  return (
    <div role="status" className="fixed inset-x-4 bottom-4 z-50 animate-fade-in sm:left-auto sm:right-6 sm:w-96">
      <div className="flex gap-3 rounded-xl bg-white p-4 text-sm shadow-overlay ring-1 ring-slate-900/10">
        <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-amber-500" aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="font-semibold text-slate-900">Menunggu persetujuan</p>
          <p className="mt-0.5 text-slate-600">{submitted.meta.message}</p>
          <p className="mt-1 truncate text-xs text-slate-500">
            {submitted.data.action_label}: {submitted.data.summary}
          </p>
          <Link to="/notifikasi" onClick={() => setSubmitted(null)} className="mt-2 inline-block font-semibold text-brand-700 hover:text-brand-800">
            {can('approval.manage') ? 'Lihat persetujuan' : 'Lihat pengajuan saya'}
          </Link>
        </div>
        <button type="button" onClick={() => setSubmitted(null)} className="-mr-1 -mt-1 grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Tutup">
          <X className="size-4" aria-hidden />
        </button>
      </div>
    </div>
  )
}

const SIDEBAR_COLLAPSED_KEY = 'sidebar.collapsed'
const NAV_OPEN_KEY = 'sidenav.open'

function readOpenSections(): Record<string, boolean> {
  try {
    return JSON.parse(localStorage.getItem(NAV_OPEN_KEY) ?? '{}') as Record<string, boolean>
  } catch {
    return {}
  }
}

function isItemActive(to: string, pathname: string) {
  return to === '/' ? pathname === '/' : pathname === to || pathname.startsWith(to + '/')
}

function SideNav({ sections, onNavigate }: { sections: NavSection[]; onNavigate: () => void }) {
  const { pathname } = useLocation()
  const pendingApprovals = useApprovalSummary(sections.some((s) => s.items.some((i) => i.approvalBadge))).data?.data.pending ?? 0
  // Explicit open/closed choices per section title; a section without one is open only while it holds the current page.
  const [open, setOpen] = useState<Record<string, boolean>>(readOpenSections)

  // Navigating into a collapsed section (link, breadcrumb, redirect) opens it so the current page stays visible.
  useEffect(() => {
    const current = sections.find((s) => s.title && s.items.some((i) => isItemActive(i.to, pathname)))
    if (current?.title && open[current.title] === false) setOpen((o) => ({ ...o, [current.title!]: true }))
  }, [pathname]) // only on navigation, not when the user collapses the current section

  useEffect(() => {
    try {
      localStorage.setItem(NAV_OPEN_KEY, JSON.stringify(open))
    } catch {
      // Storage unavailable (private mode): the choice simply is not remembered.
    }
  }, [open])

  return (
    <nav className="flex-1 space-y-1 overflow-y-auto px-3 pb-6 pt-2">
      {sections.map((section, i) => {
        const hasActive = section.items.some((item) => isItemActive(item.to, pathname))
        const expanded = !section.title || (open[section.title] ?? hasActive)
        const listId = `sidenav-${i}`
        return (
          <div key={section.title ?? i} className={clsx(section.group && 'pt-3')}>
            {/* The group label is skipped when it would only repeat the section title right below it. */}
            {section.group && section.group !== section.title && (
              <p className="px-2.5 pb-0.5 text-[0.6875rem] font-semibold uppercase tracking-wider text-brand-400/80" aria-hidden>
                {section.group}
              </p>
            )}
            {section.title && (
              <button
                type="button"
                onClick={() => setOpen((o) => ({ ...o, [section.title!]: !expanded }))}
                className={clsx(
                  'flex min-h-9 w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm font-semibold transition-colors hover:bg-white/8 hover:text-white',
                  hasActive && !expanded ? 'text-white' : 'text-brand-200',
                )}
                aria-expanded={expanded}
                aria-controls={listId}
              >
                {section.title}
                <ChevronDown className={clsx('size-4 shrink-0 transition-transform duration-200', !expanded && '-rotate-90')} aria-hidden />
              </button>
            )}
            <div className={clsx('grid transition-[grid-template-rows] duration-200 ease-out', expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')} inert={!expanded}>
              <ul id={listId} className={clsx('space-y-0.5 overflow-hidden', section.title && expanded && 'pb-2')}>
            {section.items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    clsx(
                      'group relative flex min-h-10 items-center gap-3 rounded-lg px-2.5 py-2 text-[0.9375rem] transition-colors duration-150',
                      isActive ? 'bg-white/12 font-semibold text-white' : 'text-brand-100/90 hover:bg-white/8 hover:text-white',
                    )
                  }
                >
                  {({ isActive }) => (
                    <>
                      {/* Active marker: shape as well as color, so the current page is not shown by color alone. */}
                      <span className={clsx('absolute inset-y-2 left-0 w-1 rounded-r-full bg-brand-300 transition-opacity', isActive ? 'opacity-100' : 'opacity-0')} aria-hidden />
                      <item.icon className={clsx('size-[1.125rem] shrink-0', isActive ? 'text-white' : 'text-brand-300 group-hover:text-white')} aria-hidden />
                      <span className="flex-1">{item.label}</span>
                      {item.approvalBadge && pendingApprovals > 0 && (
                        <span className="rounded-full bg-red-500 px-1.5 text-xs font-semibold leading-5 text-white" aria-label={`${pendingApprovals} menunggu`}>
                          {pendingApprovals > 99 ? '99+' : pendingApprovals}
                        </span>
                      )}
                    </>
                  )}
                </NavLink>
              </li>
            ))}
              </ul>
            </div>
          </div>
        )
      })}
    </nav>
  )
}

function UserMenu({ name, email, onLogout }: { name: string; email: string; onLogout: () => void }) {
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  return (
    <div className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="flex items-center gap-2.5 rounded-lg py-1.5 pl-1.5 pr-2 text-left transition-colors hover:bg-slate-100"
        aria-label="Menu pengguna"
        aria-expanded={open}
        aria-haspopup="true"
      >
        <Avatar name={name} light />
        <span className="hidden min-w-0 max-w-48 sm:block">
          <span className="block truncate text-sm font-semibold text-slate-900">{name}</span>
          <span className="block truncate text-xs text-slate-500">{email}</span>
        </span>
        <ChevronDown className={clsx('size-4 text-slate-400 transition-transform', open && 'rotate-180')} aria-hidden />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
          <div className="absolute right-0 z-20 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-1.5 shadow-lg">
            <div className="border-b border-slate-100 px-2.5 py-2">
              <p className="truncate text-sm font-semibold text-slate-900">{name}</p>
              <p className="truncate text-xs text-slate-500">{email}</p>
            </div>
            <button type="button" onClick={onLogout} className="mt-1 flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sm text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900">
              <LogOut className="size-4" aria-hidden />
              Keluar
            </button>
          </div>
        </>
      )}
    </div>
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
  const { can } = useAuth()
  const segments = pathname.split('/').filter(Boolean)
  if (segments.length === 0) return null

  return (
    <nav aria-label="Breadcrumb" className="mb-3">
      <ol className="flex flex-wrap items-center gap-1 text-sm text-slate-500">
        <li>
          <Link to="/" className="hover:text-brand-700">
            Beranda
          </Link>
        </li>
        {segments.map((segment, i) => {
          const to = '/' + segments.slice(0, i + 1).join('/')
          const label = segment === 'notifikasi' && !can('approval.manage') ? 'Pengajuan Saya' : (breadcrumbLabels[segment] ?? 'Detail')
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
