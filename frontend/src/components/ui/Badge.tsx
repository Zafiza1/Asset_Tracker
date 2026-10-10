import clsx from 'clsx'
import type { ReactNode } from 'react'
import type { BadgeTone } from './badgeTones'

const tones: Record<BadgeTone, string> = {
  green: 'bg-green-50 text-green-700 ring-green-600/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  red: 'bg-red-50 text-red-700 ring-red-600/20',
  slate: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  blue: 'bg-brand-50 text-brand-700 ring-brand-600/20',
}

export function Badge({ tone = 'slate', children }: { tone?: BadgeTone; children: ReactNode }) {
  return <span className={clsx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>{children}</span>
}
