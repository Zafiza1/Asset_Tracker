import { ArrowLeftRight, BookOpen, Boxes, Building2, FileClock, FolderTree, KeyRound, LayoutDashboard, Layers, MapPin, Settings, ShieldCheck, Stethoscope, Users, type LucideIcon } from 'lucide-react'

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
 * Tenant menu: one entry per module (container). Only implemented modules appear.
 * Internal/generic data (generic catalog, definitions, technical ids) is deliberately not
 * a user menu; developers inspect it with `php artisan asset:inspect`.
 */
export const tenantNavigation: NavSection[] = [
  { items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard }] },
  {
    title: 'Aset',
    items: [
      { to: '/aset', label: 'Pendataan Aset', icon: Boxes, permission: 'asset.view' },
      { to: '/transaksi', label: 'Transaksi Aset', icon: ArrowLeftRight, permission: 'transaction.view' },
    ],
  },
  {
    title: 'Master Data',
    items: [
      { to: '/master/kategori', label: 'Kategori Aset', icon: FolderTree, permission: 'master.tenant.view' },
      { to: '/master/lokasi', label: 'Lokasi', icon: MapPin, permission: 'master.tenant.view' },
      { to: '/master/kondisi', label: 'Kondisi Aset', icon: Stethoscope, permission: 'master.tenant.view' },
    ],
  },
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
  {
    title: 'Generic Master',
    items: [
      { to: '/platform/master/kategori', label: 'Kategori', icon: FolderTree, permission: 'master.generic.view' },
      { to: '/platform/master/definisi', label: 'Definisi & Versi', icon: Layers, permission: 'master.generic.view' },
      { to: '/platform/master/referensi', label: 'Referensi', icon: BookOpen, permission: 'master.generic.view' },
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
  aset: 'Pendataan Aset',
  transaksi: 'Transaksi Aset',
  master: 'Master Data',
  kategori: 'Kategori',
  definisi: 'Definisi',
  versi: 'Versi',
  lokasi: 'Lokasi',
  kondisi: 'Kondisi Aset',
  referensi: 'Referensi',
}

/** Intermediate path segments without a page of their own: shown as text, not links. */
export const breadcrumbTextOnly = new Set(['/platform', '/master', '/platform/master', '/platform/master/versi'])
