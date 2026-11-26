import { ArrowLeftRight, Bell, BookOpen, Boxes, ClipboardList, Building2, FileClock, FileText, FolderTree, Home, IdCard, LayoutDashboard, MapPinned, Network, QrCode, Store, Truck, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Shown only when the user has this permission (the API enforces it independently). */
  permission?: string
  /** Hidden when the user has this permission (a lighter entry that a fuller one replaces). */
  hiddenWithPermission?: string
  /** Shows the number of decisions waiting in Persetujuan (transaction steps and data change requests). */
  inboxBadge?: boolean
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
      // Right under Beranda, so what waits for a decision is seen first. One approval list:
      // approvers (Super Admin, Supervisor) decide data change requests and the steps of
      // transactions under "Persetujuan"; their own changes need no approval. Everyone else
      // follows the outcome of their submissions under "Pengajuan Saya".
      { to: '/notifikasi', label: 'Persetujuan', icon: Bell, permission: 'approval.manage', inboxBadge: true },
      { to: '/notifikasi', label: 'Pengajuan Saya', icon: ClipboardList, hiddenWithPermission: 'approval.manage', inboxBadge: true },
      { to: '/dashboard-aset', label: 'Dashboard Aset', icon: LayoutDashboard, permission: 'dashboard.view' },
    ],
  },
  {
    group: 'Operasional',
    title: 'Manajemen Aset',
    items: [
      { to: '/aset', label: 'Data Aset', icon: Boxes, permission: 'asset.view' },
      // Cylinders going out to customers and coming back, and where their vehicles are now.
      { to: '/transaksi', label: 'Transaksi', icon: ArrowLeftRight, permission: 'delivery.view' },
      { to: '/tracking', label: 'Tracking', icon: MapPinned, permission: 'delivery.view' },
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
      { to: '/master/kendaraan', label: 'Kendaraan', icon: Truck, permission: 'master.tenant.view' },
      { to: '/master/karyawan', label: 'Karyawan', icon: IdCard, permission: 'master.tenant.view' },
      { to: '/master/divisi', label: 'Divisi', icon: Network, permission: 'master.tenant.view' },
      { to: '/master/customer', label: 'Customer', icon: Store, permission: 'master.tenant.view' },
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
      { to: '/organisasi', label: 'Business Unit', icon: Building2, permission: 'organization.view' },
      { to: '/pengguna', label: 'Pengguna', icon: Users, permission: 'user.view' },
      { to: '/audit', label: 'Audit Trail', icon: FileClock, permission: 'audit.view' },
    ],
  },
  { items: [{ to: '/panduan', label: 'Panduan', icon: BookOpen }] },
]

/**
 * The menu entry of the current page: the one whose address matches the most of the path, so
 * of nested addresses only the closest entry is current.
 * Pages without an entry of their own fall under the closest one (/transaksi/riwayat → Transaksi).
 */
export function currentNavTarget(sections: NavSection[], pathname: string): string | null {
  let best: string | null = null
  for (const { to } of sections.flatMap((s) => s.items)) {
    const matches = to === '/' ? pathname === '/' : pathname === to || pathname.startsWith(to + '/')
    if (matches && (best === null || to.length > best.length)) best = to
  }
  return best
}

/** Breadcrumb labels per first path segment. */
export const breadcrumbLabels: Record<string, string> = {
  organisasi: 'Business Unit',
  pengguna: 'Pengguna',
  role: 'Role & Permission',
  undangan: 'Undangan',
  'kode-lisensi': 'Kode Lisensi',
  modul: 'Modul',
  barcode: 'Barcode',
  tracking: 'Tracking',
  audit: 'Audit Trail',
  notifikasi: 'Persetujuan',
  panduan: 'Panduan',
  baru: 'Baru',
  'ubah-password': 'Ubah Password',
  aset: 'Data Aset',
  transaksi: 'Transaksi',
  riwayat: 'Riwayat',
  berjalan: 'Transaksi Berjalan',
  laporan: 'Laporan',
  template: 'Template Laporan',
  desain: 'Desainer',
  master: 'Master Data',
  kategori: 'Kategori',
  definisi: 'Definisi',
  versi: 'Versi',
  karyawan: 'Karyawan',
  customer: 'Customer',
  divisi: 'Divisi',
  kendaraan: 'Kendaraan',
  referensi: 'Referensi',
}

/** Intermediate path segments without a page of their own: shown as text, not links. */
export const breadcrumbTextOnly = new Set(['/master', '/modul'])
