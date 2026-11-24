import { ArrowLeftRight, Bell, BookOpen, Boxes, ClipboardList, Building2, FileClock, FileText, FolderTree, Home, KeyRound, LayoutDashboard, MapPin, Network, QrCode, Users, Workflow, type LucideIcon } from 'lucide-react'

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
      { to: '/dashboard-aset', label: 'Dashboard Aset', icon: LayoutDashboard, permission: 'dashboard.view' },
    ],
  },
  {
    group: 'Operasional',
    title: 'Manajemen Aset',
    items: [
      { to: '/aset', label: 'Data Aset', icon: Boxes, permission: 'asset.view' },
      // The Transaction Center (D-50): recording, and every running transaction.
      { to: '/transaksi', label: 'Transaksi', icon: ArrowLeftRight, permission: 'transaction.view' },
      // One approval list: approvers (Super Admin, Admin, Supervisor) decide data change requests and
      // the steps of transactions under "Persetujuan"; their own changes need no approval. Everyone
      // else follows the outcome of their submissions under "Pengajuan Saya".
      { to: '/notifikasi', label: 'Persetujuan', icon: Bell, permission: 'approval.manage', inboxBadge: true },
      { to: '/notifikasi', label: 'Pengajuan Saya', icon: ClipboardList, hiddenWithPermission: 'approval.manage', inboxBadge: true },
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
      { to: '/master/departemen', label: 'Departemen', icon: Network, permission: 'master.tenant.view' },
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

/**
 * The menu entry of the current page: the one whose address matches the most of the path, so
 * on /transaksi/jenis only "Jenis Transaksi" is current, not also "Transaksi" (/transaksi).
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
  baru: 'Baru',
  'ubah-password': 'Ubah Password',
  aset: 'Data Aset',
  transaksi: 'Transaksi',
  riwayat: 'Riwayat',
  kosong: 'Kosong',
  rules: 'Rules',
  'cara-kerja': 'Cara kerja',
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
  departemen: 'Departemen',
  referensi: 'Referensi',
}

/** Intermediate path segments without a page of their own: shown as text, not links. */
export const breadcrumbTextOnly = new Set(['/master', '/modul'])
