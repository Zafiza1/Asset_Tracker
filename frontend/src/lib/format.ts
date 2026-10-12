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

export const masterStatusLabel: Record<string, string> = {
  active: 'Aktif',
  inactive: 'Nonaktif',
  archived: 'Diarsipkan',
}

export const versionStatusLabel: Record<string, string> = {
  draft: 'Draft',
  released: 'Dirilis',
  deprecated: 'Deprecated',
}

export const dataTypeLabel: Record<string, string> = {
  string: 'Teks singkat',
  text: 'Teks panjang',
  integer: 'Bilangan bulat',
  decimal: 'Desimal',
  boolean: 'Ya/Tidak',
  date: 'Tanggal',
  enum: 'Pilihan tunggal',
  multi_enum: 'Pilihan ganda',
}

export const overridableLabel: Record<string, string> = {
  label: 'Label',
  help_text: 'Teks bantuan',
  required: 'Wajib (hanya diperketat)',
  options: 'Opsi (subset)',
  validation: 'Validasi (hanya dipersempit)',
  default_value: 'Nilai default',
}

/** Human-readable attribute value for tables and previews. */
export function formatAttributeValue(value: unknown, options?: { value: string; label: string }[] | null): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  const label = (v: string) => options?.find((o) => o.value === v)?.label ?? v
  if (Array.isArray(value)) return value.map((v) => label(String(v))).join(', ')
  return label(String(value))
}
