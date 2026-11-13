import { Archive, Ban, CalendarClock, CheckCircle2, CircleDashed, CircleSlash, Clock, Mail, PauseCircle, PencilLine, TimerOff, UserCheck, XCircle, type LucideIcon } from 'lucide-react'

export type BadgeTone = 'green' | 'amber' | 'red' | 'slate' | 'blue'

/** Consistent colors for lifecycle-like statuses across the app. */
export const statusTone: Record<string, BadgeTone> = {
  active: 'green',
  invited: 'blue',
  deactivated: 'slate',
  archived: 'slate',
  inactive: 'amber',
  draft: 'blue',
  released: 'green',
  deprecated: 'slate',
  pending: 'amber',
  accepted: 'green',
  approved: 'green',
  rejected: 'red',
  cancelled: 'slate',
  expired: 'slate',
  revoked: 'red',
  suspended: 'red',
  scheduled: 'blue',
  available: 'blue',
  assigned: 'green',
}

/**
 * The icon that goes with each status, so a status is never told apart by color alone
 * (color-blind users, grayscale prints, sunlight on a phone).
 */
export const statusIcon: Record<string, LucideIcon> = {
  active: CheckCircle2,
  invited: Mail,
  deactivated: Ban,
  archived: Archive,
  inactive: PauseCircle,
  draft: PencilLine,
  released: CheckCircle2,
  deprecated: Archive,
  pending: Clock,
  accepted: CheckCircle2,
  approved: CheckCircle2,
  rejected: XCircle,
  cancelled: CircleSlash,
  expired: TimerOff,
  revoked: XCircle,
  suspended: Ban,
  scheduled: CalendarClock,
  available: CircleDashed,
  assigned: UserCheck,
}
