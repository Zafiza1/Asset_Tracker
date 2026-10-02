import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiClient, ApiError, saveSession, type Session, type Template } from '../core/api/client'
import { errorText, inputClass } from './shared'

const accountFields = [['name', 'Full name', 'text'], ['email', 'Email', 'email'], ['password', 'Password', 'password'], ['password_confirmation', 'Confirm password', 'password']] as const
type AccountField = typeof accountFields[number][0]

/**
 * Onboarding: 1) create the account, 2) create the first organization and a
 * project from a template (the user becomes owner/admin). Step 2 can be
 * skipped and done later under Organizations.
 */
export function RegisterPage({ onAuthenticated }: { onAuthenticated: (session: Session) => void }) {
  const [session, setSession] = useState<Session | null>(null)
  return <main className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
    {session ? <WorkspaceStep session={session} onDone={next => { saveSession(next); onAuthenticated(next) }} /> : <AccountStep onRegistered={setSession} />}
  </main>
}

function AccountStep({ onRegistered }: { onRegistered: (session: Session) => void }) {
  const [form, setForm] = useState<Record<AccountField, string>>({ name: '', email: '', password: '', password_confirmation: '' })
  const [error, setError] = useState(''); const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({}); const [loading, setLoading] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(''); setFieldErrors({})
    if (form.password !== form.password_confirmation) { setFieldErrors({ password_confirmation: ['Passwords do not match.'] }); return }
    setLoading(true)
    try {
      const registered = await new ApiClient({ token: '' }).register(form)
      onRegistered({ token: registered.token, user: { name: registered.user.name, email: registered.user.email, organizations: [], projects: [] } })
    } catch (err) {
      if (err instanceof ApiError) setFieldErrors(err.errors ?? {})
      setError(errorText(err, 'Unable to register'))
    } finally { setLoading(false) }
  }
  return <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-lg bg-white p-6 shadow">
    <h1 className="text-xl font-bold">Create an account</h1><p className="text-sm text-slate-500">Step 1 of 2 · your account</p>
    {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    {accountFields.map(([field, label, type]) => <label key={field} className="block text-sm">{label}<input required type={type} minLength={type === 'password' ? 8 : undefined} maxLength={255} value={form[field]} onChange={e => setForm({ ...form, [field]: e.target.value })} className={inputClass} />{fieldErrors[field]?.map(message => <span key={message} className="mt-1 block text-xs text-red-600">{message}</span>)}</label>)}
    <button disabled={loading} className="w-full rounded bg-blue-600 py-2 text-white disabled:opacity-60">{loading ? 'Creating account…' : 'Continue'}</button>
    <p className="text-center text-sm text-slate-500">Already have an account? <Link to="/" className="text-blue-600 hover:underline">Sign in</Link></p>
  </form>
}

function WorkspaceStep({ session, onDone }: { session: Session; onDone: (session: Session) => void }) {
  const [templates, setTemplates] = useState<Template[]>([])
  const [form, setForm] = useState({ organization: '', project: '', template_id: '' })
  const [error, setError] = useState(''); const [loading, setLoading] = useState(false)
  useEffect(() => { new ApiClient(session).templates().then(r => setTemplates(r.data.filter(t => t.status === 'available'))).catch(() => setTemplates([])) }, [session])
  const template = templates.find(t => String(t.id) === form.template_id)

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(''); setLoading(true)
    try {
      const api = new ApiClient(session)
      const organization = await api.createOrganization(form.organization.trim())
      const project = await api.createProjectFromTemplate(organization.id, { name: form.project.trim(), template_id: form.template_id ? Number(form.template_id) : undefined })
      onDone({
        ...session, organizationId: organization.id, projectId: project.id,
        user: { ...session.user, organizations: [{ id: organization.id, name: organization.name }], projects: [{ id: project.id, name: project.name, organization_id: organization.id }] },
      })
    } catch (err) { setError(errorText(err, 'Unable to create the workspace')) } finally { setLoading(false) }
  }
  return <form onSubmit={submit} className="w-full max-w-md space-y-4 rounded-lg bg-white p-6 shadow">
    <h1 className="text-xl font-bold">Set up your workspace</h1><p className="text-sm text-slate-500">Step 2 of 2 · your organization and first project</p>
    {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <label className="block text-sm">Organization name<input required maxLength={255} className={inputClass} value={form.organization} onChange={e => setForm({ ...form, organization: e.target.value })} placeholder="PT Example Indonesia" /></label>
    <label className="block text-sm">Project name<input required maxLength={255} className={inputClass} value={form.project} onChange={e => setForm({ ...form, project: e.target.value })} placeholder="Vehicle Tracker" /></label>
    <label className="block text-sm">Template<select className={inputClass} value={form.template_id} onChange={e => setForm({ ...form, template_id: e.target.value })}>
      <option value="">Blank project (no template)</option>{templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
    </select>{template && <span className="mt-1 block text-xs text-slate-500">{template.description} Modules: {template.default_modules?.join(', ') || 'none'}.</span>}</label>
    <button disabled={loading} className="w-full rounded bg-blue-600 py-2 text-white disabled:opacity-60">{loading ? 'Creating workspace…' : 'Create workspace'}</button>
    <button type="button" className="w-full text-sm text-slate-500 hover:underline" onClick={() => onDone(session)}>Skip for now</button>
  </form>
}
