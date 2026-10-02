import { useState, type FormEvent } from 'react'
import { ApiClient, saveSession, type Membership, type Session } from '../core/api/client'

type LoginUser = { name?: string; email?: string; default_organization_id?: number | null; default_project_id?: number | null; organizations?: Membership[]; projects?: Membership[] }

/** Start in the user's saved default project, else their first project. */
function initialContext(user: LoginUser): Pick<Session, 'organizationId' | 'projectId'> {
  const projects = user.projects ?? []
  const project = projects.find(p => p.id === user.default_project_id) ?? projects[0]
  return project
    ? { organizationId: project.organization_id, projectId: project.id }
    : { organizationId: user.default_organization_id ?? user.organizations?.[0]?.id }
}

export function LoginPage({ onAuthenticated }: { onAuthenticated: (session: Session) => void }) {
  const [email, setEmail] = useState(''); const [password, setPassword] = useState(''); const [error, setError] = useState(''); const [loading, setLoading] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setLoading(true); setError('')
    try {
      const data = await new ApiClient({ token: '' }).login(email, password)
      const user: LoginUser = data.user ?? {}
      const session: Session = {
        token: data.token,
        user: {
          name: user.name, email: user.email,
          organizations: (user.organizations ?? []).map(o => ({ id: o.id, name: o.name })),
          projects: (user.projects ?? []).map(p => ({ id: p.id, name: p.name, organization_id: p.organization_id })),
        },
        ...initialContext(user),
      }
      saveSession(session); onAuthenticated(session)
    } catch (err) { setError(err instanceof Error ? err.message : 'Unable to sign in') } finally { setLoading(false) }
  }
  return <main className="flex min-h-screen items-center justify-center bg-slate-100 p-4"><form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-lg bg-white p-6 shadow"><h1 className="text-xl font-bold">Asset Tracker PaaS</h1><p className="text-sm text-slate-500">Sign in to your organization workspace.</p>{error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}<label className="block text-sm">Email<input required type="email" value={email} onChange={e => setEmail(e.target.value)} className="mt-1 w-full rounded border p-2" /></label><label className="block text-sm">Password<input required type="password" value={password} onChange={e => setPassword(e.target.value)} className="mt-1 w-full rounded border p-2" /></label><button disabled={loading} className="w-full rounded bg-blue-600 py-2 text-white disabled:opacity-60">{loading ? 'Signing in…' : 'Sign in'}</button></form></main>
}
