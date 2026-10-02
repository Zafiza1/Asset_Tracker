import { useCallback, useState } from 'react'
import type { ApiClient, Template, TemplateVersion } from '../core/api/client'
import { ErrorMessage, PageHeader, StatusBadge, errorText, formatDate, secondaryButtonClass, useLoad } from './shared'

/** Platform template catalog: blueprints (with versions) that new projects start from. */
export function TemplatesPage({ api }: { api: ApiClient }) {
  const load = useCallback(() => api.templates(), [api])
  const { value, error } = useLoad(load)
  return <>
    <PageHeader title="Templates" description="Versioned blueprints for new projects. A project keeps the template version it was created with, so new releases never break it." />
    <ErrorMessage error={error} />
    <div className="mt-5 grid gap-4 lg:grid-cols-2">{value?.data.map(template => <TemplateCard key={template.id} api={api} template={template} />)}</div>
  </>
}

function TemplateCard({ api, template }: { api: ApiClient; template: Template }) {
  const [versions, setVersions] = useState<TemplateVersion[] | null>(null)
  const [error, setError] = useState('')
  const toggle = async () => {
    if (versions) { setVersions(null); return }
    try {
      const list = (await api.templateVersions(template.id)).data
      // The list omits each version's modules; fetch them per version.
      setVersions(await Promise.all(list.map(v => api.templateVersion(template.id, v.id))))
    } catch (e) { setError(errorText(e)) }
  }
  return <article className="rounded-lg border bg-white p-5">
    <div className="flex items-start justify-between gap-3">
      <div><h3 className="font-semibold">{template.name}</h3><p className="text-xs text-slate-500">{template.category ?? 'general'} · current v{template.current_version?.version ?? template.version}</p></div>
      <StatusBadge status={template.status} />
    </div>
    <p className="mt-2 text-sm text-slate-600">{template.description}</p>
    <div className="mt-3 flex flex-wrap gap-1">{(template.default_modules ?? []).map(m => <span key={m} className="rounded bg-slate-100 px-2 py-0.5 text-xs">{m}</span>)}</div>
    <button className={`${secondaryButtonClass} mt-4`} onClick={toggle}>{versions ? 'Hide versions' : 'Show versions'}</button>
    <ErrorMessage error={error} />
    {versions && <ul className="mt-3 divide-y text-sm">{versions.map(version => <li key={version.id} className="py-2">
      <p className="flex items-center gap-2 font-medium">v{version.version} <StatusBadge status={version.status} /><span className="text-xs font-normal text-slate-500">{formatDate(version.released_at)}</span></p>
      {version.description && <p className="text-xs text-slate-500">{version.description}</p>}
      <p className="mt-1 text-xs text-slate-600">Modules: {version.modules?.length ? version.modules.map(m => `${m.name}${m.version_constraint ? ` (${m.version_constraint})` : ''}${m.required ? '' : ' · optional'}`).join(', ') : 'none'}</p>
    </li>)}</ul>}
  </article>
}
