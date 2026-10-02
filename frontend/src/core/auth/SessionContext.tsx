import { createContext, useContext } from 'react'
import type { Session } from '../api/client'

export type SessionControls = {
  session: Session
  /** Reloads the organization/project list; optionally switches to a project. */
  refreshWorkspaces: (selectProjectId?: number) => Promise<void>
}

export const SessionContext = createContext<SessionControls>({ session: { token: '' }, refreshWorkspaces: async () => {} })
export const useSession = () => useContext(SessionContext)
