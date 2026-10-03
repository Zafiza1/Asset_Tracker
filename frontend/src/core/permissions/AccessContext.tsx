/* eslint-disable react-refresh/only-export-components */
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import type { ApiClient, Session } from '../api/client'

/**
 * What the signed-in user may do in the current organization/project, from
 * /auth/me. The UI only hides what is not allowed; the backend still
 * authorizes every request (policies + permissions) and only routes a
 * module's endpoints while that module is enabled.
 */
export type Access = { ready: boolean; platformAdmin: boolean; can: (permission: string) => boolean; hasModule: (slug: string) => boolean }

const initial: Access = { ready: false, platformAdmin: false, can: () => false, hasModule: () => false }
const AccessContext = createContext<Access>(initial)

export function AccessProvider({ api, session, children }: { api: ApiClient; session: Session; children: ReactNode }) {
  const [access, setAccess] = useState<Access>(initial)
  useEffect(() => {
    let active = true
    api.me().then(me => {
      const platformAdmin = (me.user.roles ?? []).some(role => role.slug === 'platform-admin')
      const granted = new Set([...(me.organization_permissions ?? []), ...(me.project_permissions ?? [])])
      const modules = new Set(me.project_modules ?? [])
      if (active) setAccess({ ready: true, platformAdmin, can: permission => platformAdmin || granted.has(permission), hasModule: slug => modules.has(slug) })
    }).catch(() => {
      // Unknown permissions: show everything and let the API decide.
      if (active) setAccess({ ready: true, platformAdmin: false, can: () => true, hasModule: () => true })
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
