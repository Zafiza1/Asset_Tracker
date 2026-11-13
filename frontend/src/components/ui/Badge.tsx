import clsx from 'clsx'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { statusIcon, statusTone, type BadgeTone } from './badgeTones'

const tones: Record<BadgeTone, string> = {
  green: 'bg-green-50 text-green-700 ring-green-600/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  red: 'bg-red-50 text-red-700 ring-red-600/20',
  slate: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  blue: 'bg-brand-50 text-brand-700 ring-brand-600/20',
}

/**
 * Small label for a status or a kind. The text always says what it is; pass `icon` for
 * statuses so the meaning does not rest on the color.
 */
export function Badge({ tone = 'slate', icon: Icon, children }: { tone?: BadgeTone; icon?: LucideIcon; children: ReactNode }) {
  return (
    <span className={clsx('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', tones[tone])}>
      {Icon && <Icon className="-ml-0.5 size-3.5 shrink-0" aria-hidden />}
      {children}
    </span>
  )
}

/** Badge of a lifecycle status (active, archived, pending, …): one color and icon per status app-wide. */
export function StatusBadge({ status, children }: { status: string; children: ReactNode }) {
  return (
    <Badge tone={statusTone[status] ?? 'slate'} icon={statusIcon[status]}>
      {children}
    </Badge>
  )
}
