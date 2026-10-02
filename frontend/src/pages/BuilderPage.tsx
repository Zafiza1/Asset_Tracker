import type { ApiClient } from '../core/api/client'
import { FieldBuilder } from '../builder/field-builder/FieldBuilder'
import { dashboardBuilder } from '../builder/dashboard-builder'
import { moduleBuilder } from '../builder/module-builder'
import { workflowBuilder } from '../builder/workflow-builder'
import { PageHeader } from './shared'

const planned = [moduleBuilder, workflowBuilder, dashboardBuilder]

/** Level 2 customization (custom fields) today; Level 3 builders arrive once Core is stable. */
export function BuilderPage({ api }: { api: ApiClient }) {
  return <>
    <PageHeader title="Builder" description="Customize this project without code changes. Field Builder is available now; the other builders are planned." />
    <h3 className="mt-6 text-lg font-semibold">Field Builder</h3>
    <div className="mt-3"><FieldBuilder api={api} /></div>
    <h3 className="mt-8 text-lg font-semibold">Planned</h3>
    <div className="mt-3 grid gap-4 sm:grid-cols-3">{planned.map(builder => <article key={builder.name} className="rounded-lg border border-dashed bg-white p-5">
      <p className="font-medium">{builder.name}</p><p className="mt-1 text-sm text-slate-600">{builder.description}</p>
      <span className="mt-3 inline-block rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Future phase</span>
    </article>)}</div>
  </>
}
