import { NavLink } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { Session } from '../api/client'

const navigation = [['/', 'Dashboard'], ['/assets', 'Assets'], ['/locations', 'Locations'], ['/movements', 'Movements'], ['/builder', 'Builder']]

/**
 * Project context lives in the session and is sent as X-Organization-Id /
 * X-Project-Id on every request; the backend re-validates membership.
 */
function ProjectSwitcher({ session, onChange }: { session: Session; onChange: (session: Session) => void }) {
  const organizations = session.user?.organizations ?? []
  const projects = session.user?.projects ?? []
  if (!projects.length) return <span className="text-xs text-slate-500">No project membership</span>
  const orgName = (id?: number) => organizations.find(o => o.id === id)?.name ?? `Organization ${id}`
  return <select aria-label="Project" className="max-w-64 rounded border p-1.5 text-sm" value={session.projectId ?? ''}
    onChange={e => { const project = projects.find(p => p.id === Number(e.target.value)); if (project) onChange({ ...session, organizationId: project.organization_id, projectId: project.id }) }}>
    {!session.projectId && <option value="">Select a project…</option>}
    {projects.map(project => <option key={project.id} value={project.id}>{orgName(project.organization_id)} / {project.name}</option>)}
  </select>
}

export function AppLayout({ children, session, onLogout, onSessionChange }: { children: ReactNode; session: Session; onLogout: () => void; onSessionChange: (session: Session) => void }) {
  return <div className="min-h-screen bg-slate-50 text-slate-900">
    <header className="border-b bg-white">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4">
        <div><h1 className="text-xl font-bold">Asset Tracker PaaS</h1><p className="text-xs text-slate-500">Tenant-scoped runtime</p></div>
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <ProjectSwitcher session={session} onChange={onSessionChange} />
          <span>{session.user?.name ?? session.user?.email ?? 'Signed in'}</span>
          <button className="rounded bg-slate-800 px-3 py-1.5 text-white" onClick={onLogout}>Sign out</button>
        </div>
      </div>
    </header>
    <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 md:flex-row md:gap-8">
      <nav className="flex shrink-0 gap-1 overflow-x-auto md:w-36 md:flex-col md:space-y-1">{navigation.map(([to, label]) => <NavLink key={to} to={to} end={to === '/'} className={({ isActive }) => `block whitespace-nowrap rounded px-3 py-2 text-sm ${isActive ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-200'}`}>{label}</NavLink>)}</nav>
      <main className="min-w-0 flex-1">{session.projectId ? children : <p className="rounded-lg border bg-white p-6 text-sm text-slate-600">Select a project to start.</p>}</main>
    </div>
  </div>
}
