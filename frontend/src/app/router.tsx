import { createBrowserRouter } from 'react-router'
import { AuditLogPage } from '../features/audit/AuditLogPage'
import { ChangePasswordPage } from '../features/auth/ChangePasswordPage'
import { LoginPage } from '../features/auth/LoginPage'
import { RegisterPage } from '../features/auth/RegisterPage'
import { AssetDetailPage } from '../features/assets/AssetDetailPage'
import { AssetsPage } from '../features/assets/AssetsPage'
import { ConditionsPage, LocationsPage, TenantCategoriesPage } from '../features/master/StructurePages'
import { OrganizationPage } from '../features/organization/OrganizationPage'
import { CreateOrganizationPage, SelectOrganizationPage } from '../features/organizations/OrganizationPages'
import { SettingsPage } from '../features/organization/SettingsPage'
import {
  PlatformCategoriesPage,
  PlatformDefinitionDetailPage,
  PlatformDefinitionsPage,
  PlatformReferencePage,
  PlatformVersionPage,
} from '../features/platform/GenericMasterPages'
import { PlatformOrganizationDetailPage } from '../features/platform/PlatformOrganizationDetailPage'
import { PlatformOrganizationsPage } from '../features/platform/PlatformOrganizationsPage'
import { RoleFormPage } from '../features/roles/RoleFormPage'
import { TransactionsPage } from '../features/transactions/TransactionsPage'
import { RolesPage } from '../features/roles/RolesPage'
import { UserCreatePage } from '../features/users/UserCreatePage'
import { UserDetailPage } from '../features/users/UserDetailPage'
import { UsersPage } from '../features/users/UsersPage'
import { AppLayout } from './AppLayout'
import { Landing } from './Landing'
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
  { path: '/daftar', element: <RegisterPage /> },
  {
    element: <RequireAuth />,
    children: [
      // Outside the app layout: no organization may be active yet.
      { path: '/pilih-organisasi', element: <SelectOrganizationPage /> },
      { path: '/organisasi/baru', element: <CreateOrganizationPage /> },
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: tenant(null, <Landing />) },
          { path: '/aset', element: tenant('asset.view', <AssetsPage />) },
          { path: '/aset/:id', element: tenant('asset.view', <AssetDetailPage />) },
          { path: '/transaksi', element: tenant('transaction.view', <TransactionsPage />) },
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
          { path: '/master/kategori', element: tenant('master.tenant.view', <TenantCategoriesPage />) },
          { path: '/master/lokasi', element: tenant('master.tenant.view', <LocationsPage />) },
          { path: '/master/kondisi', element: tenant('master.tenant.view', <ConditionsPage />) },
          { path: '/platform/organisasi', element: platform('platform.organization.view', <PlatformOrganizationsPage />) },
          { path: '/platform/organisasi/:id', element: platform('platform.organization.view', <PlatformOrganizationDetailPage />) },
          { path: '/platform/master/kategori', element: platform('master.generic.view', <PlatformCategoriesPage />) },
          { path: '/platform/master/definisi', element: platform('master.generic.view', <PlatformDefinitionsPage />) },
          { path: '/platform/master/definisi/:id', element: platform('master.generic.view', <PlatformDefinitionDetailPage />) },
          { path: '/platform/master/versi/:id', element: platform('master.generic.view', <PlatformVersionPage />) },
          { path: '/platform/master/referensi', element: platform('master.generic.view', <PlatformReferencePage />) },
          { path: '/platform/audit', element: platform('platform.audit.view', <AuditLogPage endpoint="/platform/audit-logs" title="Audit Platform" />) },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
])
