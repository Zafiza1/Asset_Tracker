export type BadgeTone = 'green' | 'amber' | 'red' | 'slate' | 'blue'

/** Consistent colors for lifecycle-like statuses across the app. */
export const statusTone: Record<string, BadgeTone> = {
  active: 'green',
  invited: 'blue',
  suspended: 'amber',
  deactivated: 'slate',
  archived: 'slate',
}
