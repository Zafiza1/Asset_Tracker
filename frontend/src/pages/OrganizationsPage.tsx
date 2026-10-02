import { useCallback, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ApiClient, Organization, Template } from '../core/api/client'
import { useSession } from '../core/auth/SessionContext'
import { Empty, ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, inputClass, secondaryButtonClass, useLoad } from './shared'

/** Control plane: organizations (tenants) and the projects inside them. */
export function OrganizationsPage({ api }: { api: ApiClient }) {
  const { session, refreshWorkspaces } = useSession()
  const navigate = useNavigate()
  const load = useCallback(async () => {
    const [organizations, templates] = await Promise.all([api.organizations(), api.templates().catch(() => ({ data: [] as Template[] }))])
    const projects = await Promise.all(organizations.data.map(o => api.projects(o.id).then(r => r.data).catch(() => [])))
    return { organizations: organizations.data, templates: templates.data.filter(t => t.status === 'available'), projects: projects.flat() }
  }, [api])
  const { value, error, loading, reload } = useLoad(load)
  const [creatingOrg, setCreatingOrg] = useState(false)
  const [orgName, setOrgName] = useState('')
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload(); await refreshWorkspaces() } catch (e) { setActionError(errorText(e)) }
  }
  const createOrganization = (event: FormEvent) => {
    event.preventDefault()
    run(async () => { await api.createOrganization(orgName.trim()); setOrgName(''); setCreatingOrg(false) }, 'Organization created. Add a project to start tracking assets.')
  }
  const openProject = async (projectId: number) => { await refreshWorkspaces(projectId); navigate('/') }

  return <>
    <PageHeader title="Organizations & projects" description="Each organization is an isolated tenant. Projects are independent asset-tracking applications built from a template."
      actions={!creatingOrg && <button className={buttonClass} onClick={() => setCreatingOrg(true)}>New organization</button>} />
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />

    {creatingOrg && <form onSubmit={createOrganization} className="mt-5 flex flex-wrap items-end gap-3 rounded-lg border bg-white p-5">
      <label className="block min-w-64 flex-1 text-sm">Organization name<input required maxLength={255} className={inputClass} value={orgName} onChange={e => setOrgName(e.target.value)} placeholder="PT Example Indonesia" /></label>
      <button className={buttonClass}>Create</button><button type="button" className={secondaryButtonClass} onClick={() => setCreatingOrg(false)}>Cancel</button>
    </form>}

    {!loading && value && !value.organizations.length && <div className="mt-5"><Empty>You do not belong to any organization yet. Create one to get started.</Empty></div>}
    <div className="mt-5 space-y-5">
      {value?.organizations.map(organization => <OrganizationCard key={organization.id} api={api} organization={organization} templates={value.templates}
        projects={value.projects.filter(p => p.organization_id === organization.id)} currentProjectId={session.projectId}
        onOpen={openProject} run={run} />)}
    </div>
  </>
}

function OrganizationCard({ api, organization, templates, projects, currentProjectId, onOpen, run }: {
  api: ApiClient; organization: Organization; templates: Template[]; projects: { id: number; name: string; description?: string | null; status: string; template?: { name: string; version: string } | null }[]
  currentProjectId?: number; onOpen: (projectId: number) => void; run: (action: () => Promise<unknown>, done: string) => Promise<void>
}) {
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', template_id: '' })
  const template = templates.find(t => String(t.id) === form.template_id)

  const createProject = (event: FormEvent) => {
    event.preventDefault()
    run(async () => {
      const project = await api.createProjectFromTemplate(organization.id, { name: form.name.trim(), description: form.description.trim() || undefined, template_id: form.template_id ? Number(form.template_id) : undefined })
      setForm({ name: '', description: '', template_id: '' }); setCreating(false)
      onOpen(project.id)
    }, 'Project created.')
  }
  const removeProject = (id: number, name: string) => { if (confirm(`Delete project "${name}"? Its data stays recoverable by a platform admin.`)) run(() => api.deleteProject(id), 'Project deleted.') }

  return <section className="rounded-lg border bg-white p-5">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><h3 className="text-lg font-semibold">{organization.name}</h3><p className="text-xs text-slate-500">{organization.slug}{organization.membership ? ` · your role: ${organization.membership}` : ''}</p></div>
      <div className="flex items-center gap-2"><StatusBadge status={organization.status} />{!creating && <button className={secondaryButtonClass} onClick={() => setCreating(true)}>New project</button>}</div>
    </div>

    {creating && <form onSubmit={createProject} className="mt-4 grid gap-3 rounded border bg-slate-50 p-4 sm:grid-cols-2">
      <label className="block text-sm">Project name<input required maxLength={255} className={inputClass} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="Vehicle Tracker" /></label>
      <label className="block text-sm">Template<select className={inputClass} value={form.template_id} onChange={e => setForm({ ...form, template_id: e.target.value })}>
        <option value="">Blank project (no template)</option>
        {templates.map(t => <option key={t.id} value={t.id}>{t.name} v{t.current_version?.version ?? t.version}</option>)}
      </select></label>
      <label className="block text-sm sm:col-span-2">Description<input maxLength={2000} className={inputClass} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} /></label>
      {template && <p className="text-xs text-slate-600 sm:col-span-2">{template.description} Installs: {template.default_modules?.join(', ') || 'no modules'}.</p>}
      <div className="flex gap-2 sm:col-span-2"><button className={buttonClass}>Create project</button><button type="button" className={secondaryButtonClass} onClick={() => setCreating(false)}>Cancel</button></div>
    </form>}

    <ul className="mt-4 divide-y">
      {projects.map(project => <li key={project.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
        <div>
          <p className="font-medium">{project.name} {project.id === currentProjectId && <span className="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-xs text-blue-800">current</span>}</p>
          <p className="text-xs text-slate-500">{project.template ? `Template: ${project.template.name} v${project.template.version}` : 'No template'}{project.description ? ` · ${project.description}` : ''}</p>
        </div>
        <div className="flex items-center gap-2"><StatusBadge status={project.status} />
          <button className={secondaryButtonClass} onClick={() => onOpen(project.id)}>Open</button>
          <button className="text-sm text-red-600 hover:underline" onClick={() => removeProject(project.id, project.name)}>Delete</button>
        </div>
      </li>)}
      {!projects.length && <li className="py-3 text-sm text-slate-500">No projects in this organization yet.</li>}
    </ul>
  </section>
}
