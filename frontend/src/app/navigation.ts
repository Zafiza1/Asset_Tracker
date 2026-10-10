import { Building2, FileClock, Home, KeyRound, Settings, ShieldCheck, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Shown only when the user has this permission (the API enforces it independently). */
  permission?: string
}

export interface NavSection {
  title?: string
  items: NavItem[]
}

/**
 * Only modules that are actually implemented appear here. Further modules
 * (master data, assets, transactions, reports) are added as their phases ship.
 */
export const tenantNavigation: NavSection[] = [
  { items: [{ to: '/', label: 'Beranda', icon: Home }] },
  {
    title: 'Administrasi',
    items: [
      { to: '/organisasi', label: 'Organisasi', icon: Building2, permission: 'organization.view' },
      { to: '/pengguna', label: 'Pengguna', icon: Users, permission: 'user.view' },
      { to: '/role', label: 'Role & Permission', icon: ShieldCheck, permission: 'role.view' },
      { to: '/pengaturan', label: 'Pengaturan', icon: Settings, permission: 'settings.manage' },
      { to: '/audit', label: 'Audit Trail', icon: FileClock, permission: 'audit.view' },
    ],
  },
  { title: 'Akun', items: [{ to: '/ubah-password', label: 'Ubah Password', icon: KeyRound }] },
]

export const platformNavigation: NavSection[] = [
  {
    title: 'Platform',
    items: [
      { to: '/platform/organisasi', label: 'Organisasi', icon: Building2, permission: 'platform.organization.view' },
      { to: '/platform/audit', label: 'Audit Platform', icon: FileClock, permission: 'platform.audit.view' },
    ],
  },
  { title: 'Akun', items: [{ to: '/ubah-password', label: 'Ubah Password', icon: KeyRound }] },
]

/** Breadcrumb labels per first path segment. */
export const breadcrumbLabels: Record<string, string> = {
  organisasi: 'Organisasi',
  pengguna: 'Pengguna',
  role: 'Role & Permission',
  pengaturan: 'Pengaturan',
  audit: 'Audit Trail',
  platform: 'Platform',
  baru: 'Baru',
  'ubah-password': 'Ubah Password',
}
