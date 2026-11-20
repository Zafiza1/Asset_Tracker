import { ArrowLeftRight, Bell, BookOpen, Boxes, ClipboardList, Building2, FileClock, FileText, FolderTree, Home, KeyRound, LayoutDashboard, Layers, ListChecks, MapPin, QrCode, Users, Workflow, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Shown only when the user has this permission (the API enforces it independently). */
  permission?: string
  /** Hidden when the user has this permission (a lighter entry that a fuller one replaces). */
  hiddenWithPermission?: string
  /** Shows the number of pending approval requests next to the label. */
  approvalBadge?: boolean
  /** Shows the number of transaction steps waiting for the user (F-07). */
  taskBadge?: boolean
}

export interface NavSection {
  /** Small uppercase group label shown above the section (e.g. "Operasional"). */
  group?: string
  title?: string
  items: NavItem[]
}

/**
 * Tenant menu: one entry per module (container). Only implemented modules appear.
 * Internal/generic data (generic catalog, definitions, technical ids) is deliberately not
 * a user menu; developers inspect it with `php artisan asset:inspect`.
 */
export const tenantNavigation: NavSection[] = [
  {
    items: [
      { to: '/', label: 'Beranda', icon: Home },
      { to: '/dashboard-aset', label: 'Dashboard Aset', icon: LayoutDashboard, permission: 'dashboard.view' },
    ],
  },
  {
    group: 'Operasional',
    title: 'Manajemen Aset',
    items: [
      { to: '/aset', label: 'Data Aset', icon: Boxes, permission: 'asset.view' },
      { to: '/transaksi', label: 'Transaksi Aset', icon: ArrowLeftRight, permission: 'transaction.view' },
      { to: '/transaksi/berjalan', label: 'Transaksi Berjalan', icon: ListChecks, permission: 'transaction.view', taskBadge: true },
      // Approval requests on one page: approvers (Super Admin, Supervisor) decide them under
      // "Persetujuan"; everyone else follows whether their own submissions were approved under "Pengajuan Saya".
      { to: '/notifikasi', label: 'Persetujuan', icon: Bell, permission: 'approval.manage', approvalBadge: true },
      { to: '/notifikasi', label: 'Pengajuan Saya', icon: ClipboardList, hiddenWithPermission: 'approval.manage' },
    ],
  },
  {
    group: 'Monitoring',
    title: 'Laporan & Analisis',
    items: [
      { to: '/laporan/template', label: 'Template Laporan', icon: FileText, permission: 'report.view' },
    ],
  },
  {
    group: 'Konfigurasi',
    title: 'Master Data',
    items: [
      { to: '/master/kategori', label: 'Kategori Aset', icon: FolderTree, permission: 'master.tenant.view' },
      { to: '/master/lokasi', label: 'Lokasi', icon: MapPin, permission: 'master.tenant.view' },
      { to: '/transaksi/jenis', label: 'Jenis Transaksi', icon: Workflow, permission: 'transaction_type.manage' },
    ],
  },
  {
    // Optional modules, one entry each; switched on and off on their own page.
    group: 'Ekstensi',
    title: 'Modul',
    items: [{ to: '/modul/barcode', label: 'Barcode', icon: QrCode, permission: 'settings.manage' }],
  },
  {
    group: 'Administrasi',
    title: 'Administrasi',
    items: [
      { to: '/organisasi', label: 'Organisasi', icon: Building2, permission: 'organization.view' },
      { to: '/pengguna', label: 'Pengguna', icon: Users, permission: 'user.view' },
      { to: '/pengaturan', label: 'Lisensi', icon: KeyRound, permission: 'license.manage' },
      { to: '/audit', label: 'Audit Trail', icon: FileClock, permission: 'audit.view' },
    ],
  },
  { items: [{ to: '/panduan', label: 'Panduan', icon: BookOpen }] },
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
]

/** Breadcrumb labels per first path segment. */
export const breadcrumbLabels: Record<string, string> = {
  organisasi: 'Organisasi',
  pengguna: 'Pengguna',
  role: 'Role & Permission',
  undangan: 'Undangan',
  'kode-lisensi': 'Kode Lisensi',
  modul: 'Modul',
  barcode: 'Barcode',
  pengaturan: 'Lisensi',
  audit: 'Audit Trail',
  notifikasi: 'Persetujuan',
  panduan: 'Panduan',
  platform: 'Platform',
  baru: 'Baru',
  'ubah-password': 'Ubah Password',
  aset: 'Data Aset',
  transaksi: 'Transaksi Aset',
  jenis: 'Jenis Transaksi',
  alur: 'Alur',
  berjalan: 'Transaksi Berjalan',
  laporan: 'Laporan',
  template: 'Template Laporan',
  desain: 'Desainer',
  master: 'Master Data',
  kategori: 'Kategori',
  definisi: 'Definisi',
  versi: 'Versi',
  lokasi: 'Lokasi',
  referensi: 'Referensi',
}

/** Intermediate path segments without a page of their own: shown as text, not links. */
export const breadcrumbTextOnly = new Set(['/platform', '/master', '/modul','/platform/master', '/platform/master/versi'])
