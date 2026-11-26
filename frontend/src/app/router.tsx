import { createBrowserRouter, Navigate } from 'react-router'
import { AuditLogPage } from '../features/audit/AuditLogPage'
import { ChangePasswordPage } from '../features/auth/ChangePasswordPage'
import { EmailVerificationPage } from '../features/auth/EmailVerificationPage'
import { InvitationPage } from '../features/auth/InvitationPage'
import { LoginPage } from '../features/auth/LoginPage'
import { RegisterPage } from '../features/auth/RegisterPage'
import { AssetDetailPage } from '../features/assets/AssetDetailPage'
import { AssetsPage } from '../features/assets/AssetsPage'
import { DeliveriesPage } from '../features/deliveries/DeliveriesPage'
import { DeliveryDetailPage } from '../features/deliveries/DeliveryDetailPage'
import { TrackingPage } from '../features/deliveries/TrackingPage'
import { CustomersPage, DepartmentsPage, EmployeesPage, TenantCategoriesPage, VehiclesPage } from '../features/master/StructurePages'
import { OrganizationPage } from '../features/organization/OrganizationPage'
import { CreateOrganizationPage, SelectOrganizationPage } from '../features/organizations/OrganizationPages'
import { ModulesPage } from '../features/organization/ModulesPage'
import { ReportRunPage } from '../features/reportDesigner/ReportRunPage'
import { ReportDesignerPage } from '../features/reportDesigner/ReportDesignerPage'
import { ReportTemplateDetailPage } from '../features/reportDesigner/ReportTemplateDetailPage'
import { ReportTemplatesPage } from '../features/reportDesigner/ReportTemplatesPage'
import { RoleFormPage } from '../features/roles/RoleFormPage'
import { TransactionsPage } from '../features/transactions/TransactionsPage'
import { TransactionRunPage } from '../features/transactionTypes/TransactionRunPage'
import { NotificationsPage } from '../features/approvals/NotificationsPage'
import { AssetBoardPage } from '../features/assetBoard/AssetBoardPage'
import { HomePage } from '../features/home/HomePage'
import { GuidePage } from '../features/guide/GuidePage'
import { LegacyRoleRedirect, RolesPage } from '../features/roles/RolesPage'
import { UserCreatePage } from '../features/users/UserCreatePage'
import { UserDetailPage } from '../features/users/UserDetailPage'
import { InvitationsPage } from '../features/users/InvitationsPage'
import { UsersPage } from '../features/users/UsersPage'
import { AppLayout } from './AppLayout'
import { RootLayout } from './RootLayout'
import { NotFound, RequireAuth, RequirePermission, RequireTenantUser, RouteError } from './guards'

const tenant = (permission: string | null, element: React.ReactNode) => (
  <RequireTenantUser>{permission ? <RequirePermission permission={permission}>{element}</RequirePermission> : element}</RequireTenantUser>
)

export const router = createBrowserRouter([
  {
    // Last-resort boundary: an error outside the app layout shows a message instead of a blank page.
    element: <RootLayout />,
    errorElement: <RouteError />,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/daftar', element: <RegisterPage /> },
      // Links from emails; both handle login on the page so the one-time secret stays in memory.
      { path: '/undangan', element: <InvitationPage /> },
      { path: '/verifikasi-email', element: <EmailVerificationPage /> },
      {
        element: <RequireAuth />,
        children: [
          // Outside the app layout: no organization may be active yet.
          { path: '/pilih-organisasi', element: <SelectOrganizationPage /> },
          { path: '/organisasi/baru', element: <CreateOrganizationPage /> },
          {
            element: <AppLayout />,
            children: [
              {
                // A page that throws keeps the sidebar and header; only the content shows the error.
                errorElement: <RouteError />,
                children: [
                  // Beranda for every member: their own work and notifications (F-06 §3).
                  { path: '/', element: tenant(null, <HomePage />) },
                  // Dashboard Aset (F-06 §4): the member's copy of their role template, plus category dashboards.
                  { path: '/dashboard-aset', element: tenant('dashboard.view', <AssetBoardPage />) },
                  { path: '/aset', element: tenant('asset.view', <AssetsPage />) },
                  { path: '/aset/:id', element: tenant('asset.view', <AssetDetailPage />) },
                  // Cylinders out to customers and back; the history of single assets is Riwayat.
                  { path: '/transaksi', element: tenant('delivery.view', <DeliveriesPage />) },
                  { path: '/transaksi/riwayat', element: tenant('transaction.view', <TransactionsPage />) },
                  { path: '/transaksi/:id', element: tenant('delivery.view', <DeliveryDetailPage />) },
                  { path: '/tracking', element: tenant('delivery.view', <TrackingPage />) },
                  // The former list of running transactions is the Riwayat tab "Sedang diproses" now; the detail stays (emails link to it).
                  { path: '/transaksi/berjalan', element: <Navigate to="/transaksi/riwayat?proses=1" replace /> },
                  { path: '/transaksi/berjalan/:id', element: tenant('transaction.view', <TransactionRunPage />) },
                  { path: '/notifikasi', element: tenant(null, <NotificationsPage />) },
                  { path: '/panduan', element: tenant(null, <GuidePage />) },
                  { path: '/laporan', element: <Navigate to="/laporan/template" replace /> },
                  { path: '/laporan/template', element: tenant('report.view', <ReportTemplatesPage />) },
                  { path: '/laporan/template/:id', element: tenant('report.view', <ReportTemplateDetailPage />) },
                  { path: '/laporan/template/:id/desain', element: tenant('report.update', <ReportDesignerPage />) },
                  { path: '/laporan/template/:id/jalankan', element: tenant('report.generate', <ReportRunPage />) },
                  { path: '/ubah-password', element: <ChangePasswordPage /> },
                  { path: '/organisasi', element: tenant('organization.view', <OrganizationPage />) },
                  { path: '/modul/barcode', element: tenant('settings.manage', <ModulesPage module="barcode" />) },
                  { path: '/pengguna', element: tenant('user.view', <UsersPage />) },
                  { path: '/pengguna/baru', element: tenant('user.create', <UserCreatePage />) },
                  { path: '/pengguna/undangan', element: tenant('user.view', <InvitationsPage />) },
                  { path: '/pengguna/role', element: tenant('role.view', <RolesPage />) },
                  { path: '/pengguna/role/baru', element: tenant('role.manage', <RoleFormPage />) },
                  { path: '/pengguna/role/:id', element: tenant('role.view', <RoleFormPage />) },
                  { path: '/pengguna/:id', element: tenant('user.view', <UserDetailPage />) },
                  // Old Role & Permission menu, now a tab under Pengguna.
                  { path: '/role', element: <Navigate to="/pengguna/role" replace /> },
                  { path: '/role/:id', element: <LegacyRoleRedirect /> },
                  { path: '/audit', element: tenant('audit.view', <AuditLogPage endpoint="/audit-logs" title="Audit Trail" />) },
                  { path: '/master/kategori', element: tenant('master.tenant.view', <TenantCategoriesPage />) },
                  { path: '/master/karyawan', element: tenant('master.tenant.view', <EmployeesPage />) },
                  { path: '/master/customer', element: tenant('master.tenant.view', <CustomersPage />) },
                  { path: '/master/divisi', element: tenant('master.tenant.view', <DepartmentsPage />) },
                  // Former address of Divisi.
                  { path: '/master/departemen', element: <Navigate to="/master/divisi" replace /> },
                  { path: '/master/kendaraan', element: tenant('master.tenant.view', <VehiclesPage />) },
                  { path: '*', element: <NotFound /> },
                ],
              },
            ],
          },
        ],
      },
    ],
  },
])
