import type { ApiClient, Membership, Session } from '../api/client'

/**
 * The organizations and projects the user can open, from the control-plane
 * API (covers organization owners and platform admins, who see projects they
 * are not a direct member of).
 */
export async function loadWorkspaces(api: ApiClient): Promise<{ organizations: Membership[]; projects: Membership[] }> {
  const organizations = (await api.organizations()).data
  const projectLists = await Promise.all(organizations.map(organization => api.projects(organization.id).then(r => r.data).catch(() => [])))
  return {
    organizations: organizations.map(o => ({ id: o.id, name: o.name })),
    projects: projectLists.flat().map(p => ({ id: p.id, name: p.name, organization_id: p.organization_id })),
  }
}

/** Keeps the selected project when still available, else falls back to the first one. */
export function withWorkspaces(session: Session, workspaces: { organizations: Membership[]; projects: Membership[] }): Session {
  const current = workspaces.projects.find(p => p.id === session.projectId) ?? workspaces.projects[0]
  return {
    ...session,
    user: { ...session.user, ...workspaces },
    organizationId: current?.organization_id ?? session.organizationId ?? workspaces.organizations[0]?.id,
    projectId: current?.id,
  }
}
