import { useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ApiClient, getSession, saveSession, type Session } from './core/api/client'
import { AppLayout } from './core/layout/AppLayout'
import { LoginPage } from './pages/LoginPage'
import { DashboardPage } from './pages/DashboardPage'
import { AssetsPage } from './pages/AssetsPage'
import { LocationsPage } from './pages/LocationsPage'
import { MovementsPage } from './pages/MovementsPage'

function ProtectedApp({ session, onLogout }: { session: Session; onLogout: () => void }) {
  const api = new ApiClient(session)
  return <AppLayout session={session} onLogout={onLogout}>
    <Routes>
      <Route path="/" element={<DashboardPage api={api} />} />
      <Route path="/assets" element={<AssetsPage api={api} />} />
      <Route path="/locations" element={<LocationsPage api={api} />} />
      <Route path="/movements" element={<MovementsPage api={api} />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  </AppLayout>
}

export default function App() {
  const [session, setSession] = useState(getSession)
  const logout = () => { saveSession(null); setSession(null) }
  return <BrowserRouter>{session
    ? <ProtectedApp session={session} onLogout={logout} />
    : <Routes><Route path="*" element={<LoginPage onAuthenticated={setSession} />} /></Routes>
  }</BrowserRouter>
}
