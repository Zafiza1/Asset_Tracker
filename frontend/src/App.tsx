import { useCallback, useEffect, useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ApiClient, getSession, saveSession, type Session } from './core/api/client'
import { SessionContext } from './core/auth/SessionContext'
import { loadWorkspaces, withWorkspaces } from './core/auth/workspaces'
import { AppLayout } from './core/layout/AppLayout'
import { AccessProvider } from './core/permissions/AccessContext'
import { LoginPage } from './pages/LoginPage'
import { RegisterPage } from './pages/RegisterPage'
import { DashboardPage } from './pages/DashboardPage'
import { OrganizationsPage } from './pages/OrganizationsPage'
import { TemplatesPage } from './pages/TemplatesPage'
import { ModulesPage } from './pages/ModulesPage'
import { MembersPage } from './pages/MembersPage'
import { WebhooksPage } from './pages/WebhooksPage'
import { ApiKeysPage } from './pages/ApiKeysPage'
import { AuditPage } from './pages/AuditPage'
import { HealthPage } from './pages/HealthPage'
import { BuilderPage } from './pages/BuilderPage'
import { AssetListPage } from './modules/asset/AssetListPage'
import { AssetDetailPage } from './modules/asset/AssetDetailPage'
import { LocationsPage } from './modules/location/LocationsPage'
import { MovementsPage } from './modules/movement/MovementsPage'
import { MaintenancePage } from './modules/maintenance/MaintenancePage'
import { CustomersPage } from './modules/customer/CustomersPage'
import { IntegrationsPage } from './integrations/IntegrationsPage'
import { DevicesPage } from './integrations/DevicesPage'

function ProtectedApp({ session, onLogout, onSessionChange }: { session: Session; onLogout: () => void; onSessionChange: (session: Session) => void }) {
  // A new client per project context, so every page reloads its data on switch.
  const api = useMemo(() => new ApiClient(session), [session])

  const refreshWorkspaces = useCallback(async (selectProjectId?: number) => {
    const workspaces = await loadWorkspaces(new ApiClient({ token: session.token }))
    onSessionChange(withWorkspaces({ ...session, projectId: selectProjectId ?? session.projectId }, workspaces))
  }, [session, onSessionChange])

  // Once per sign-in: the login payload only lists direct project
  // memberships, the control-plane API also covers owners and admins.
  useEffect(() => { refreshWorkspaces().catch(() => undefined) }, [session.token]) // eslint-disable-line react-hooks/exhaustive-deps

  const controls = useMemo(() => ({ session, refreshWorkspaces }), [session, refreshWorkspaces])
  return <SessionContext.Provider value={controls}><AccessProvider api={api} session={session}>
    <AppLayout session={session} onLogout={onLogout} onSessionChange={onSessionChange}>
      <Routes>
        <Route path="/" element={<DashboardPage api={api} />} />
        <Route path="/assets" element={<AssetListPage api={api} />} />
        <Route path="/assets/:systemId" element={<AssetDetailPage api={api} />} />
        <Route path="/locations" element={<LocationsPage api={api} />} />
        <Route path="/movements" element={<MovementsPage api={api} />} />
        <Route path="/customers" element={<CustomersPage api={api} />} />
        <Route path="/maintenance" element={<MaintenancePage api={api} />} />
        <Route path="/devices" element={<DevicesPage api={api} />} />
        <Route path="/integrations" element={<IntegrationsPage api={api} />} />
        <Route path="/modules" element={<ModulesPage api={api} />} />
        <Route path="/builder" element={<BuilderPage api={api} />} />
        <Route path="/webhooks" element={<WebhooksPage api={api} />} />
        <Route path="/api-keys" element={<ApiKeysPage api={api} />} />
        <Route path="/members" element={<MembersPage api={api} />} />
        <Route path="/audit" element={<AuditPage api={api} />} />
        <Route path="/health" element={<HealthPage api={api} />} />
        <Route path="/organizations" element={<OrganizationsPage api={api} />} />
        <Route path="/templates" element={<TemplatesPage api={api} />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </AppLayout>
  </AccessProvider></SessionContext.Provider>
}

export default function App() {
  const [session, setSession] = useState(getSession)
  const update = useCallback((next: Session | null) => { saveSession(next); setSession(next) }, [])
  return <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>{session
    ? <ProtectedApp session={session} onLogout={() => update(null)} onSessionChange={update} />
    : <Routes><Route path="/register" element={<RegisterPage onAuthenticated={setSession} />} /><Route path="*" element={<LoginPage onAuthenticated={setSession} />} /></Routes>
  }</BrowserRouter>
}
