import type { ReactNode } from 'react'
import { ModuleBoundary } from '../../app/ModuleBoundary'
import { Card } from '../../components/ui/Card'

/** Card with a title and its own error boundary, so one failing widget leaves the rest standing. */
export function Widget({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <Card title={title} className="h-full" actions={action}>
      <ModuleBoundary name={title} compact>
        {children}
      </ModuleBoundary>
    </Card>
  )
}

/** One headline number inside a widget. */
export function Kpi({ label, value, note }: { label: string; value: ReactNode; note?: ReactNode }) {
  return (
    <div className="min-w-0 rounded-lg bg-slate-50 px-3 py-2.5">
      <p className="truncate text-xs font-medium text-slate-600">{label}</p>
      <p className="mt-0.5 truncate text-xl font-semibold text-slate-900">{value}</p>
      {note && <p className="mt-0.5 text-xs text-slate-500">{note}</p>}
    </div>
  )
}
