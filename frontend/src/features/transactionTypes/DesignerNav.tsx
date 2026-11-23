import clsx from 'clsx'
import { NavLink } from 'react-router'

/**
 * Tabs of a transaction type in the Transaction Designer (F08-07a): the guided wizard (simple
 * mode), the form, the flow on the canvas (advanced mode) and the named rules. Simulasi follows
 * with F-08f.
 */
export function DesignerNav({ typeId, ruleCount }: { typeId: string; ruleCount?: number }) {
  const tabs = [
    { to: `/transaksi/jenis/${typeId}/panduan`, label: 'Panduan', end: true },
    { to: `/transaksi/jenis/${typeId}`, label: 'Formulir', end: true },
    { to: `/transaksi/jenis/${typeId}/alur`, label: 'Alur (lanjutan)', end: true },
    { to: `/transaksi/jenis/${typeId}/rules`, label: 'Rules', end: true, count: ruleCount },
  ]
  return (
    <nav aria-label="Bagian jenis transaksi" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
      {tabs.map((t) => (
        <NavLink
          key={t.to}
          to={t.to}
          end={t.end}
          className={({ isActive }) =>
            clsx(
              '-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold',
              isActive ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-600 hover:text-slate-900',
            )
          }
        >
          {t.label}
          {t.count ? <span className="rounded-full bg-slate-100 px-1.5 text-xs tabular-nums text-slate-700">{t.count}</span> : null}
        </NavLink>
      ))}
    </nav>
  )
}
