import { useCallback, useState } from 'react'
import type { ApiClient, Module, ProjectModule } from '../core/api/client'
import { Can } from '../core/permissions/AccessContext'
import { ErrorMessage, Notice, PageHeader, StatusBadge, buttonClass, errorText, formatDate, secondaryButtonClass, useLoad } from './shared'

/**
 * Module lifecycle for the selected project:
 * available → installed → configured → enabled ⇄ disabled → uninstalled.
 */
export function ModulesPage({ api }: { api: ApiClient }) {
  const load = useCallback(async () => {
    const [catalog, installed] = await Promise.all([api.modules(), api.projectModules()])
    return { catalog: catalog.data, installed: installed.data }
  }, [api])
  const { value, error, reload } = useLoad(load)
  const [message, setMessage] = useState('')
  const [actionError, setActionError] = useState('')
  const [configuring, setConfiguring] = useState<string | null>(null)

  const run = async (action: () => Promise<unknown>, done: string) => {
    setActionError(''); setMessage('')
    try { await action(); setMessage(done); reload() } catch (e) { setActionError(errorText(e)) }
  }

  const installed = new Map((value?.installed ?? []).map(m => [m.module, m]))
  return <>
    <PageHeader title="Modules" description="Business functionality installed per project. Modules are versioned: an installed version stays until you upgrade it." />
    <ErrorMessage error={error || actionError} />
    <Notice message={message} tone="success" />
    <div className="mt-5 grid gap-4 lg:grid-cols-2">
      {value?.catalog.map(module => <ModuleCard key={module.slug} module={module} projectModule={installed.get(module.slug)}
        configuring={configuring === module.slug} onConfigure={open => setConfiguring(open ? module.slug : null)}
        install={() => run(() => api.installModule(module.slug), `${module.name} installed. Enable it to start using it.`)}
        action={a => run(() => api.moduleAction(module.slug, a), `${module.name} ${a === 'upgrade' ? 'upgraded' : `${a}d`}.`)}
        uninstall={() => confirm(`Uninstall ${module.name}? Its configuration is removed from this project.`) && run(() => api.uninstallModule(module.slug), `${module.name} uninstalled.`)}
        saveConfig={config => run(async () => { await api.configureModule(module.slug, config); setConfiguring(null) }, `${module.name} configured.`)} />)}
    </div>
  </>
}

function ModuleCard({ module, projectModule, configuring, onConfigure, install, action, uninstall, saveConfig }: {
  module: Module; projectModule?: ProjectModule; configuring: boolean; onConfigure: (open: boolean) => void
  install: () => void; action: (a: 'enable' | 'disable' | 'upgrade') => void; uninstall: () => void; saveConfig: (config: Record<string, unknown>) => void
}) {
  const [text, setText] = useState('')
  const [parseError, setParseError] = useState('')
  const openConfig = () => { setText(JSON.stringify(projectModule?.configuration ?? {}, null, 2)); setParseError(''); onConfigure(true) }
  const submitConfig = () => { try { saveConfig(JSON.parse(text || '{}')) } catch { setParseError('Configuration must be valid JSON.') } }
  const status = projectModule?.status

  return <article className="rounded-lg border bg-white p-5">
    <div className="flex items-start justify-between gap-3">
      <div><h3 className="font-semibold">{module.name} {module.is_core && <span className="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">core</span>}</h3>
        <p className="text-xs text-slate-500">{module.slug} · {module.category ?? 'general'} · latest v{module.latest_version ?? '—'}</p></div>
      <StatusBadge status={status ?? 'available'} />
    </div>
    <p className="mt-2 text-sm text-slate-600">{module.description}</p>
    {projectModule && <p className="mt-2 text-xs text-slate-500">Installed v{projectModule.version} · {formatDate(projectModule.installed_at)}{projectModule.upgrade_available ? ` · v${projectModule.latest_version} available` : ''}</p>}

    <div className="mt-4 flex flex-wrap gap-2">
      {!projectModule && <Can permission="module.install"><button className={buttonClass} onClick={install}>Install</button></Can>}
      {projectModule && status !== 'enabled' && <Can permission="module.enable"><button className={buttonClass} onClick={() => action('enable')}>Enable</button></Can>}
      {status === 'enabled' && <Can permission="module.disable"><button className={secondaryButtonClass} onClick={() => action('disable')}>Disable</button></Can>}
      {projectModule && <Can permission="module.configure"><button className={secondaryButtonClass} onClick={() => configuring ? onConfigure(false) : openConfig()}>Configure</button></Can>}
      {projectModule?.upgrade_available && <Can permission="module.install"><button className={secondaryButtonClass} onClick={() => action('upgrade')}>Upgrade to v{projectModule.latest_version}</button></Can>}
      {projectModule && status !== 'enabled' && <Can permission="module.uninstall"><button className="text-sm text-red-600 hover:underline" onClick={uninstall}>Uninstall</button></Can>}
    </div>

    {configuring && projectModule && <div className="mt-4 space-y-2">
      {Object.keys(projectModule.config_schema).length > 0 && <p className="text-xs text-slate-500">Schema: <code className="break-all">{JSON.stringify(projectModule.config_schema)}</code></p>}
      <textarea rows={6} className="w-full rounded border p-2 font-mono text-xs" value={text} onChange={e => setText(e.target.value)} />
      <ErrorMessage error={parseError} />
      <div className="flex gap-2"><button className={buttonClass} onClick={submitConfig}>Save configuration</button><button className={secondaryButtonClass} onClick={() => onConfigure(false)}>Cancel</button></div>
    </div>}
  </article>
}
