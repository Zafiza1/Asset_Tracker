import type { User } from '../../lib/api/types'

export const statusActions = {
  activate: { label: 'Aktifkan', confirm: 'Pengguna dapat login kembali.', variant: 'secondary' as const },
  deactivate: { label: 'Nonaktifkan', confirm: 'Akun dinonaktifkan dan kode lisensi akan terlepas. Pengguna dapat didaftarkan kembali dengan email yang sama.', variant: 'danger' as const },
}
export type UserStatusAction = keyof typeof statusActions

/** The status change offered for a user, if any: only active accounts can be deactivated here. */
export function availableStatusAction(user: User): UserStatusAction | null {
  return user.status === 'active' ? 'deactivate' : null
}
