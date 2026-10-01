import { NavLink } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { Session } from '../api/client'
const navigation = [['/', 'Dashboard'], ['/assets', 'Assets'], ['/locations', 'Locations'], ['/movements', 'Movements']]
export function AppLayout({ children, session, onLogout }: { children: ReactNode; session: Session; onLogout: () => void }) {
  return <div className="min-h-screen bg-slate-50 text-slate-900"><header className="border-b bg-white"><div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4"><div><h1 className="text-xl font-bold">Asset Tracker PaaS</h1><p className="text-xs text-slate-500">Tenant-scoped runtime</p></div><div className="flex items-center gap-3 text-sm"><span>{session.user?.name ?? session.user?.email ?? 'Signed in'}</span><button className="rounded bg-slate-800 px-3 py-1.5 text-white" onClick={onLogout}>Sign out</button></div></div></header><div className="mx-auto flex max-w-7xl gap-8 px-4 py-6"><nav className="w-36 shrink-0 space-y-1">{navigation.map(([to, label]) => <NavLink key={to} to={to} end={to === '/'} className={({ isActive }) => `block rounded px-3 py-2 text-sm ${isActive ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-200'}`}>{label}</NavLink>)}</nav><main className="min-w-0 flex-1">{children}</main></div></div>
}
