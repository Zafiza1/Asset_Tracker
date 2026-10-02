import { useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ApiClient, getSession, saveSession, type Session } from './core/api/client'
import { AppLayout } from './core/layout/AppLayout'
import { LoginPage } from './pages/LoginPage'
import { DashboardPage } from './pages/DashboardPage'
import { AssetListPage } from './modules/asset/AssetListPage'
import { AssetDetailPage } from './modules/asset/AssetDetailPage'
import { LocationsPage } from './pages/LocationsPage'
import { MovementsPage } from './pages/MovementsPage'
import { BuilderPage } from './pages/BuilderPage'

function ProtectedApp({ session, onLogout, onSessionChange }: { session: Session; onLogout: () => void; onSessionChange: (session: Session) => void }) {
  // A new client per project context, so every page reloads its data on switch.
  const api = useMemo(() => new ApiClient(session), [session])
  return <AppLayout session={session} onLogout={onLogout} onSessionChange={onSessionChange}>
    <Routes>
      <Route path="/" element={<DashboardPage api={api} />} />
      <Route path="/assets" element={<AssetListPage api={api} />} />
      <Route path="/assets/:systemId" element={<AssetDetailPage api={api} />} />
      <Route path="/locations" element={<LocationsPage api={api} />} />
      <Route path="/movements" element={<MovementsPage api={api} />} />
      <Route path="/builder" element={<BuilderPage api={api} />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  </AppLayout>
}

export default function App() {
  const [session, setSession] = useState(getSession)
  const update = (next: Session | null) => { saveSession(next); setSession(next) }
  return <BrowserRouter>{session
    ? <ProtectedApp session={session} onLogout={() => update(null)} onSessionChange={update} />
    : <Routes><Route path="*" element={<LoginPage onAuthenticated={setSession} />} /></Routes>
  }</BrowserRouter>
}
