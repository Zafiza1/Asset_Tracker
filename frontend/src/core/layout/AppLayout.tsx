import { NavLink, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { Session } from '../api/client'
import { useAccess } from '../permissions/AccessContext'
import { navigation, projectFreePaths } from '../routing/navigation'

/**
 * Project context lives in the session and is sent as X-Organization-Id /
 * X-Project-Id on every request; the backend re-validates membership.
 */
function ProjectSwitcher({ session, onChange }: { session: Session; onChange: (session: Session) => void }) {
  const organizations = session.user?.organizations ?? []
  const projects = session.user?.projects ?? []
  if (!projects.length) return <span className="text-xs text-slate-500">No project yet</span>
  const orgName = (id?: number) => organizations.find(o => o.id === id)?.name ?? `Organization ${id}`
  return <select aria-label="Project" className="max-w-72 rounded border p-1.5 text-sm" value={session.projectId ?? ''}
    onChange={e => { const project = projects.find(p => p.id === Number(e.target.value)); if (project) onChange({ ...session, organizationId: project.organization_id, projectId: project.id }) }}>
    {!session.projectId && <option value="">Select a project…</option>}
    {organizations.map(organization => <optgroup key={organization.id} label={organization.name}>
      {projects.filter(p => p.organization_id === organization.id).map(project => <option key={project.id} value={project.id}>{project.name}</option>)}
    </optgroup>)}
    {projects.filter(p => !organizations.some(o => o.id === p.organization_id)).map(project => <option key={project.id} value={project.id}>{orgName(project.organization_id)} / {project.name}</option>)}
  </select>
}

export function AppLayout({ children, session, onLogout, onSessionChange }: { children: ReactNode; session: Session; onLogout: () => void; onSessionChange: (session: Session) => void }) {
  const { can, hasModule, ready } = useAccess()
  const { pathname } = useLocation()
  const needsProject = !projectFreePaths.some(path => pathname === path || pathname.startsWith(`${path}/`))
  const groups = navigation
    .map(group => ({ ...group, items: group.items.filter(item => (!item.permission || (ready && session.projectId && can(item.permission))) && (!item.module || (ready && hasModule(item.module)))) }))
    .filter(group => group.items.length)

  return <div className="min-h-screen bg-slate-50 text-slate-900">
    <header className="border-b bg-white">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4">
        <div><h1 className="text-xl font-bold">Asset Tracker PaaS</h1><p className="text-xs text-slate-500">Multi-tenant asset tracking platform</p></div>
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <ProjectSwitcher session={session} onChange={onSessionChange} />
          <span>{session.user?.name ?? session.user?.email ?? 'Signed in'}</span>
          <button className="rounded bg-slate-800 px-3 py-1.5 text-white" onClick={onLogout}>Sign out</button>
        </div>
      </div>
    </header>
    <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 md:flex-row md:gap-8">
      <nav className="flex shrink-0 gap-4 overflow-x-auto md:w-44 md:flex-col">
        {groups.map(group => <div key={group.title} className="flex gap-1 md:flex-col">
          <p className="hidden px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400 md:block">{group.title}</p>
          {group.items.map(item => <NavLink key={item.to} to={item.to} end={item.to === '/'} className={({ isActive }) => `block whitespace-nowrap rounded px-3 py-2 text-sm ${isActive ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-200'}`}>{item.label}</NavLink>)}
        </div>)}
      </nav>
      <main className="min-w-0 flex-1">{!needsProject || session.projectId ? children : <div className="rounded-lg border bg-white p-6 text-sm text-slate-600">
        <p className="font-medium text-slate-800">No project selected.</p>
        <p className="mt-1">Pick a project at the top, or create one under <NavLink to="/organizations" className="text-blue-600 hover:underline">Organizations</NavLink>.</p>
      </div>}</main>
    </div>
  </div>
}
