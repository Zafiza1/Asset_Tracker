const dateTime = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
const dateOnly = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' })

export function formatDateTime(value: string | null | undefined): string {
  return value ? dateTime.format(new Date(value)) : '—'
}

export function formatDate(value: string | null | undefined): string {
  return value ? dateOnly.format(new Date(value)) : '—'
}

export const userStatusLabel: Record<string, string> = {
  invited: 'Diundang',
  active: 'Aktif',
  suspended: 'Ditangguhkan',
  deactivated: 'Nonaktif',
}

export const orgStatusLabel: Record<string, string> = {
  active: 'Aktif',
  suspended: 'Ditangguhkan',
  archived: 'Diarsipkan',
}

export const scopeTypeLabel: Record<string, string> = {
  organization: 'Seluruh organisasi',
  branch: 'Cabang',
  department: 'Departemen',
  location: 'Lokasi',
}

export const permissionGroupLabel: Record<string, string> = {
  organization: 'Organisasi',
  user: 'Pengguna',
  role: 'Role',
  master: 'Master Data',
  asset: 'Aset',
  document: 'Dokumen',
  transaction: 'Transaksi',
  report: 'Laporan',
  audit: 'Audit',
  workflow: 'Workflow',
  settings: 'Pengaturan',
}
