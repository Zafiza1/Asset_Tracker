import { createBrowserRouter } from 'react-router'
import { AuditLogPage } from '../features/audit/AuditLogPage'
import { ChangePasswordPage } from '../features/auth/ChangePasswordPage'
import { LoginPage } from '../features/auth/LoginPage'
import { HomePage } from '../features/home/HomePage'
import { OrganizationPage } from '../features/organization/OrganizationPage'
import { SettingsPage } from '../features/organization/SettingsPage'
import { PlatformOrganizationDetailPage } from '../features/platform/PlatformOrganizationDetailPage'
import { PlatformOrganizationsPage } from '../features/platform/PlatformOrganizationsPage'
import { RoleFormPage } from '../features/roles/RoleFormPage'
import { RolesPage } from '../features/roles/RolesPage'
import { UserCreatePage } from '../features/users/UserCreatePage'
import { UserDetailPage } from '../features/users/UserDetailPage'
import { UsersPage } from '../features/users/UsersPage'
import { AppLayout } from './AppLayout'
import { NotFound, RequireAuth, RequirePermission, RequireUserType } from './guards'

const tenant = (permission: string | null, element: React.ReactNode) => (
  <RequireUserType type="tenant">{permission ? <RequirePermission permission={permission}>{element}</RequirePermission> : element}</RequireUserType>
)
const platform = (permission: string, element: React.ReactNode) => (
  <RequireUserType type="platform">
    <RequirePermission permission={permission}>{element}</RequirePermission>
  </RequireUserType>
)

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: tenant(null, <HomePage />) },
          { path: '/ubah-password', element: <ChangePasswordPage /> },
          { path: '/organisasi', element: tenant('organization.view', <OrganizationPage />) },
          { path: '/pengaturan', element: tenant('settings.manage', <SettingsPage />) },
          { path: '/pengguna', element: tenant('user.view', <UsersPage />) },
          { path: '/pengguna/baru', element: tenant('user.create', <UserCreatePage />) },
          { path: '/pengguna/:id', element: tenant('user.view', <UserDetailPage />) },
          { path: '/role', element: tenant('role.view', <RolesPage />) },
          { path: '/role/baru', element: tenant('role.manage', <RoleFormPage />) },
          { path: '/role/:id', element: tenant('role.view', <RoleFormPage />) },
          { path: '/audit', element: tenant('audit.view', <AuditLogPage endpoint="/audit-logs" title="Audit Trail" />) },
          { path: '/platform/organisasi', element: platform('platform.organization.view', <PlatformOrganizationsPage />) },
          { path: '/platform/organisasi/:id', element: platform('platform.organization.view', <PlatformOrganizationDetailPage />) },
          { path: '/platform/audit', element: platform('platform.audit.view', <AuditLogPage endpoint="/platform/audit-logs" title="Audit Platform" />) },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
])
