import { BookOpen, Building2, FileClock, FolderTree, GitBranch, Home, KeyRound, Layers, Library, MapPin, Network, Settings, ShieldCheck, Stethoscope, Users, type LucideIcon } from 'lucide-react'

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
 * (assets, transactions, reports) are added as their phases ship.
 * Attributes are managed inside each definition version, not as a separate menu.
 */
export const tenantNavigation: NavSection[] = [
  { items: [{ to: '/', label: 'Beranda', icon: Home }] },
  {
    title: 'Master Data',
    items: [
      { to: '/master/kategori', label: 'Kategori Aset', icon: FolderTree, permission: 'master.tenant.view' },
      { to: '/master/definisi', label: 'Definisi Aset', icon: Layers, permission: 'master.tenant.view' },
      { to: '/master/katalog', label: 'Katalog Generic Master', icon: Library, permission: 'catalog.generic.view' },
      { to: '/master/lokasi', label: 'Lokasi', icon: MapPin, permission: 'master.tenant.view' },
      { to: '/master/cabang', label: 'Cabang', icon: GitBranch, permission: 'master.tenant.view' },
      { to: '/master/departemen', label: 'Departemen', icon: Network, permission: 'master.tenant.view' },
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
  master: 'Master Data',
  kategori: 'Kategori',
  definisi: 'Definisi',
  'definisi-versi': 'Versi Definisi',
  versi: 'Versi',
  katalog: 'Katalog Generic Master',
  lokasi: 'Lokasi',
  cabang: 'Cabang',
  departemen: 'Departemen',
  kondisi: 'Kondisi Aset',
  referensi: 'Referensi',
}

/** Intermediate path segments without a page of their own: shown as text, not links. */
export const breadcrumbTextOnly = new Set(['/platform', '/master', '/platform/master', '/master/definisi-versi', '/platform/master/versi'])
