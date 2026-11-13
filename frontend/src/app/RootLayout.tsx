import { Outlet } from 'react-router'
import { Toaster } from '../components/ui/Toaster'

/** Wraps every route: the page, plus the one place where toasts appear (login pages included). */
export function RootLayout() {
  return (
    <>
      <Outlet />
      <Toaster />
    </>
  )
}
