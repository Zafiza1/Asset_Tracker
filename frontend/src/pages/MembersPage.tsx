import { useCallback, useState, type FormEvent } from 'react'
import type { ApiClient, Member } from '../core/api/client'
import { useSession } from '../core/auth/SessionContext'
import { ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, inputClass, label, useLoad } from './shared'

type Scope = 'projects' | 'organizations'
// The backend decides who may grant what (no role above your own).
const roles: Record<Scope, string[]> = {
  projects: ['project-admin', 'manager', 'operator', 'viewer', 'integration-manager', 'module-manager'],
  organizations: ['organization-owner', 'project-admin', 'manager', 'operator', 'viewer', 'integration-manager', 'module-manager'],
}

/** Users, their roles, and access to the current project and its organization. */
export function MembersPage({ api }: { api: ApiClient }) {
  const { session } = useSession()
  const [scope, setScope] = useState<Scope>('projects')
  const scopeId = scope === 'projects' ? session.projectId : session.organizationId
  const load = useCallback(() => scopeId ? api.members(scope, scopeId) : Promise.resolve({ data: [] as Member[] }), [api, scope, scopeId])
  const { value, error, reload } = useLoad(load)
  const [form, setForm] = useState({ email: '', role: 'viewer' })
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload() } catch (e) { setActionError(errorText(e)) }
  }
  const add = (event: FormEvent) => {
    event.preventDefault()
    if (scopeId) run(async () => { await api.addMember(scope, scopeId, form.email.trim(), form.role); setForm({ email: '', role: 'viewer' }) }, 'Member added.')
  }
  const projectName = session.user?.projects?.find(p => p.id === session.projectId)?.name
  const orgName = session.user?.organizations?.find(o => o.id === session.organizationId)?.name

  return <>
    <PageHeader title="Members & roles" description="Access is permission-based: each role grants a set of permissions within an organization or a single project." />
    <div className="mt-4 flex gap-2">{(['projects', 'organizations'] as Scope[]).map(s => <button key={s} onClick={() => setScope(s)}
      className={`rounded px-3 py-1.5 text-sm ${scope === s ? 'bg-blue-600 text-white' : 'border bg-white'}`}>{s === 'projects' ? `Project: ${projectName ?? '—'}` : `Organization: ${orgName ?? '—'}`}</button>)}</div>
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />

    <form onSubmit={add} className="mt-5 flex flex-wrap items-end gap-3 rounded-lg border bg-white p-5">
      <label className="block min-w-64 flex-1 text-sm">User email<input required type="email" className={inputClass} value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} placeholder="The user must already have an account" /></label>
      <label className="block text-sm">Role<select className={inputClass} value={form.role} onChange={e => setForm({ ...form, role: e.target.value })}>{roles[scope].map(r => <option key={r} value={r}>{label(r)}</option>)}</select></label>
      <button className={buttonClass}>Add member</button>
    </form>

    <div className="mt-5 overflow-x-auto rounded-lg border bg-white">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-slate-50 text-slate-500"><tr><th className="p-3">Name</th><th className="p-3">Email</th><th className="p-3">Role</th><th className="p-3">Status</th><th className="p-3"></th></tr></thead>
        <tbody>{value?.data.map(member => <tr key={member.id} className="border-t">
          <td className="p-3">{member.name}</td><td className="p-3">{member.email}</td>
          <td className="p-3"><select className="rounded border p-1 text-sm" value={member.roles[0] ?? ''} onChange={e => scopeId && run(() => api.updateMember(scope, scopeId, member.id, e.target.value || null), 'Role updated.')}>
            <option value="">No role</option>{[...new Set([...roles[scope], ...member.roles])].map(r => <option key={r} value={r}>{label(r)}</option>)}
          </select></td>
          <td className="p-3"><StatusBadge status={member.status} /></td>
          <td className="p-3 text-right"><button className="text-sm text-red-600 hover:underline" onClick={() => scopeId && confirm(`Remove ${member.name}?`) && run(() => api.removeMember(scope, scopeId, member.id), 'Member removed.')}>Remove</button></td>
        </tr>)}
          {value && !value.data.length && <tr><td colSpan={5} className="p-6 text-center text-slate-500">No members.</td></tr>}</tbody>
      </table>
    </div>
  </>
}
