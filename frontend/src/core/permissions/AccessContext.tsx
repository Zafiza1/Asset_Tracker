/* eslint-disable react-refresh/only-export-components */
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import type { ApiClient, Session } from '../api/client'

/**
 * What the signed-in user may do in the current organization/project, from
 * /auth/me. The UI only hides what is not allowed; the backend still
 * authorizes every request (policies + permissions).
 */
export type Access = { ready: boolean; platformAdmin: boolean; can: (permission: string) => boolean }

const AccessContext = createContext<Access>({ ready: false, platformAdmin: false, can: () => false })

export function AccessProvider({ api, session, children }: { api: ApiClient; session: Session; children: ReactNode }) {
  const [access, setAccess] = useState<Access>({ ready: false, platformAdmin: false, can: () => false })
  useEffect(() => {
    let active = true
    api.me().then(me => {
      const platformAdmin = (me.user.roles ?? []).some(role => role.slug === 'platform-admin')
      const granted = new Set([...(me.organization_permissions ?? []), ...(me.project_permissions ?? [])])
      if (active) setAccess({ ready: true, platformAdmin, can: permission => platformAdmin || granted.has(permission) })
    }).catch(() => {
      // Unknown permissions: show everything and let the API decide.
      if (active) setAccess({ ready: true, platformAdmin: false, can: () => true })
    })
    return () => { active = false }
  }, [api, session.organizationId, session.projectId])
  return <AccessContext.Provider value={access}>{children}</AccessContext.Provider>
}

export const useAccess = () => useContext(AccessContext)

/** Renders children only when the user holds the permission. */
export function Can({ permission, children }: { permission: string; children: ReactNode }) {
  return useAccess().can(permission) ? <>{children}</> : null
}
