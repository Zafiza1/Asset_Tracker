import { DashboardPage } from '../features/dashboard/DashboardPage'
import { HomePage } from '../features/home/HomePage'
import { useAuth } from '../lib/auth/context'

/** The dashboard is the landing page; users without report access get the account overview. */
export function Landing() {
  const { can } = useAuth()
  return can('report.view') ? <DashboardPage /> : <HomePage />
}
