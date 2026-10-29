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
  expired: 'slate',
  revoked: 'red',
}
