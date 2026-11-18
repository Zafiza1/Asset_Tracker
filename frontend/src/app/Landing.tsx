import { DashboardPage } from '../features/dashboard/DashboardPage'
import { HomePage } from '../features/home/HomePage'
import { TaskInboxCard } from '../features/transactionTypes/TaskInboxCard'
import { useAuth } from '../lib/auth/context'

/** The dashboard is the landing page; users without report access get the account overview. */
export function Landing() {
  const { can } = useAuth()
  return (
    <>
      {can('transaction.view') && <TaskInboxCard />}
      {can('report.view') ? <DashboardPage /> : <HomePage />}
    </>
  )
}
